<?php

namespace App\Jobs;

use App\Models\Acta;
use App\Models\DocumentIndex;
use App\Models\OpinionCumplimiento;
use App\Models\Regulation;
use App\Services\DocumentMetadataExtractor;
use App\Services\DocumentTextExtractor;
use App\Services\VectorIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessUploadedPdfJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public string $documentClass,
        public int $documentId,
        public string $documentType,
        public int $userId,
    ) {
    }

    public function handle(
        DocumentTextExtractor $textExtractor,
        DocumentMetadataExtractor $metadataExtractor,
        VectorIndexer $vectorIndexer,
    ): void {
        if (! class_exists($this->documentClass)) {
            return;
        }

        $document = $this->documentClass::query()->find($this->documentId);

        if (! $document) {
            return;
        }

        [$disk, $path] = $this->resolveStorageInfo($document);

        if (! $path) {
            return;
        }

        $index = DocumentIndex::updateOrCreate([
            'documentable_type' => $this->documentClass,
            'documentable_id' => $this->documentId,
        ], [
            'user_id' => $this->userId,
            'document_type' => $this->documentType,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'status' => 'pending',
            'error_message' => null,
        ]);

        try {
            $binary = Storage::disk($disk)->get($path);
            $tmp = tempnam(sys_get_temp_dir(), 'pdf_');
            file_put_contents($tmp, $binary);

            $extracted = $textExtractor->extract($tmp);
            @unlink($tmp);

            $text = trim((string) ($extracted['text'] ?? ''));

            if ($text === '') {
                throw new \RuntimeException('No text extracted from PDF.');
            }

            $chunks = $this->chunkText($text);

            $metadata = $metadataExtractor->extract($this->documentType, $text, [
                'namespace' => 'licitaciones-'.$this->documentType,
                'document_id' => $this->documentId,
                'document_type' => $this->documentType,
                'user_id' => $this->userId,
                'chunks' => $chunks,
            ]);

            $structuredSummary = $this->structuredSummaryForIndex($this->documentType, $metadata);
            if ($structuredSummary !== null) {
                array_unshift($chunks, $structuredSummary);
            }

            $index->update([
                'extraction_method' => (string) ($extracted['method'] ?? 'unknown'),
                'extracted_text' => $text,
                'metadata' => $metadata,
                'chunk_count' => count($chunks),
                'status' => 'processed',
                'error_message' => null,
                'vector_index_error' => null,
            ]);

            $this->applyExtractedMetadata($document, $metadata);
        } catch (\Throwable $e) {
            Log::error('Document text extraction failed', [
                'document_type' => $this->documentType,
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);

            $index->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Vector indexing is kept in a separate try-catch so an API quota or
        // connectivity error does not mark the document as 'failed'. The
        // extracted text is preserved and the record stays 'processed' so it
        // can be re-queued later via: php artisan documents:requeue-indexing
        try {
            $indexed = $vectorIndexer->index(
                namespace: 'licitaciones-'.$this->documentType,
                baseId: $this->documentType.'-'.$this->documentId,
                chunks: $chunks,
                metadata: [
                    'document_type' => $this->documentType,
                    'document_id' => $this->documentId,
                    'storage_path' => $path,
                    'user_id' => $this->userId,
                ],
            );

            if ($indexed) {
                $index->update([
                    'status' => 'indexed',
                    'indexed_at' => now(),
                    'vector_index_error' => null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Vector indexing failed (document stays processed)', [
                'document_type' => $this->documentType,
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);

            $index->update(['vector_index_error' => $e->getMessage()]);
            // Do not re-throw: job completes so it does not enter failed_jobs.
        }
    }

    private function resolveStorageInfo(object $document): array
    {
        $path = match (true) {
            $document instanceof Acta => $document->documento_path,
            $document instanceof OpinionCumplimiento => $document->documento_path,
            $document instanceof Regulation => $document->source_pdf_path,
            default => null,
        };

        if (! $path) {
            return ['s3', null];
        }

        try {
            if (Storage::disk('s3')->exists($path)) {
                return ['s3', $path];
            }
        } catch (\Throwable) {
            // Ignore S3 availability issues and continue with fallback disk detection.
        }

        try {
            if (Storage::disk('public')->exists($path)) {
                return ['public', $path];
            }
        } catch (\Throwable) {
            // Keep default behavior below if public disk has issues.
        }

        return ['s3', $path];
    }

    private function applyExtractedMetadata(object $document, array $metadata): void
    {
        if ($document instanceof Acta) {
            $updates = [];

            if (! $document->notaria_numero && ! empty($metadata['notaria_numero'])) {
                $updates['notaria_numero'] = $metadata['notaria_numero'];
            }
            if (! $document->notaria_lugar && ! empty($metadata['notaria_lugar'])) {
                $updates['notaria_lugar'] = $metadata['notaria_lugar'];
            }
            if (! $document->notario_nombre && ! empty($metadata['notario_nombre'])) {
                $updates['notario_nombre'] = $metadata['notario_nombre'];
            }
            if (! $document->escritura_numero && ! empty($metadata['escritura_numero'])) {
                $updates['escritura_numero'] = $metadata['escritura_numero'];
            }
            if (! $document->libro_numero && ! empty($metadata['libro_numero'])) {
                $updates['libro_numero'] = $metadata['libro_numero'];
            }
            if (! $document->rpc_folio && ! empty($metadata['rpc_folio'])) {
                $updates['rpc_folio'] = $metadata['rpc_folio'];
            }
            if (! $document->rpc_lugar && ! empty($metadata['rpc_lugar'])) {
                $updates['rpc_lugar'] = $metadata['rpc_lugar'];
            }
            if (! $document->rpc_fecha_inscripcion && ! empty($metadata['rpc_fecha_inscripcion'])) {
                $updates['rpc_fecha_inscripcion'] = $metadata['rpc_fecha_inscripcion'];
            }
            if (! $document->fecha_registro && ! empty($metadata['fecha_registro'])) {
                $updates['fecha_registro'] = $metadata['fecha_registro'];
            }
            if (! $document->fecha_inscripcion && ! empty($metadata['fecha_inscripcion'])) {
                $updates['fecha_inscripcion'] = $metadata['fecha_inscripcion'];
            }
            if (! $document->acto && ! empty($metadata['acto'])) {
                $updates['acto'] = $metadata['acto'];
            }

            if (empty($document->apoderados) && ! empty($metadata['apoderados']) && is_array($metadata['apoderados'])) {
                $updates['apoderados'] = $metadata['apoderados'];
            }

            if (empty($document->participacion_accionaria) && ! empty($metadata['participacion_accionaria']) && is_array($metadata['participacion_accionaria'])) {
                $updates['participacion_accionaria'] = $metadata['participacion_accionaria'];
            }

            if (empty($document->consejo_administracion) && ! empty($metadata['consejo_administracion']) && is_array($metadata['consejo_administracion'])) {
                $updates['consejo_administracion'] = $metadata['consejo_administracion'];
            }

            if (empty($document->direccion_empresa) && ! empty($metadata['direccion_empresa']) && is_array($metadata['direccion_empresa'])) {
                $updates['direccion_empresa'] = $metadata['direccion_empresa'];
            }

            if ($updates !== []) {
                $document->fill($updates);
                $document->save();
            }
        }

        if ($document instanceof OpinionCumplimiento) {
            $document->fill(array_filter([
                'tipo' => $document->tipo ?: ($metadata['tipo'] ?? null),
                'estado' => $document->estado ?: ($metadata['estado'] ?? null),
                'fecha_emision' => $document->fecha_emision ?: ($metadata['fecha_emision'] ?? null),
                'fecha_vigencia' => $document->fecha_vigencia ?: ($metadata['fecha_vigencia'] ?? null),
            ]));
            $document->save();
        }

        if ($document instanceof Regulation) {
            $document->fill(array_filter([
                'title' => $document->title ?: ($metadata['title'] ?? null),
                'regulatory_body' => $document->regulatory_body ?: ($metadata['regulatory_body'] ?? null),
                'general_description' => $document->general_description ?: ($metadata['general_description'] ?? null),
            ]));
            $document->save();
        }
    }

    private function chunkText(string $text, int $chunkSize = 1200, int $overlap = 150): array
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        $length = mb_strlen($text);

        if ($length === 0) {
            return [];
        }

        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $chunk = mb_substr($text, $start, $chunkSize);
            $chunks[] = trim($chunk);
            $start += max($chunkSize - $overlap, 1);
        }

        return array_values(array_filter($chunks));
    }

    private function structuredSummaryForIndex(string $documentType, array $metadata): ?string
    {
        if ($documentType !== 'acta') {
            return null;
        }

        $apoderados = [];
        foreach (($metadata['apoderados'] ?? []) as $apoderado) {
            if (! is_array($apoderado)) {
                continue;
            }

            $apoderados[] = implode(' | ', array_filter([
                'Nombre: '.($apoderado['nombre_completo'] ?? null),
                'INE: '.($apoderado['ine'] ?? null),
                'Poder: '.($apoderado['poder_documento'] ?? null),
                'Facultades: '.($apoderado['facultades_otorgadas'] ?? null),
            ]));
        }

        $participacion = [];
        foreach (($metadata['participacion_accionaria'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $participacion[] = trim((string) (($item['socio'] ?? 'Socio').' '.($item['porcentaje'] ?? '')));
        }

        $requiredMissing = implode(', ', $metadata['required_missing_fields'] ?? []);

        $lines = array_filter([
            'RESUMEN ESTRUCTURADO DEL ACTA',
            'Fecha de registro: '.($metadata['fecha_registro'] ?? 'N/A'),
            'RPC Folio: '.($metadata['rpc_folio'] ?? 'N/A'),
            'RPC Fecha inscripción: '.($metadata['rpc_fecha_inscripcion'] ?? 'N/A'),
            'RPC Lugar: '.($metadata['rpc_lugar'] ?? 'N/A'),
            'Notaría número: '.($metadata['notaria_numero'] ?? 'N/A'),
            'Notaría lugar: '.($metadata['notaria_lugar'] ?? 'N/A'),
            'Notario: '.($metadata['notario_nombre'] ?? 'N/A'),
            'Escritura número: '.($metadata['escritura_numero'] ?? 'N/A'),
            'Libro número: '.($metadata['libro_numero'] ?? 'N/A'),
            'Fecha inscripción: '.($metadata['fecha_inscripcion'] ?? 'N/A'),
            'Acto: '.($metadata['acto'] ?? 'N/A'),
            'Apoderados: '.(empty($apoderados) ? 'N/A' : implode(' || ', $apoderados)),
            'Participación accionaria: '.(empty($participacion) ? 'N/A' : implode(' || ', $participacion)),
            'Consejo de administración: '.(empty($metadata['consejo_administracion']) ? 'N/A' : implode(' | ', $metadata['consejo_administracion'])),
            'Dirección de empresa: '.(empty($metadata['direccion_empresa']) ? 'N/A' : implode(' | ', $metadata['direccion_empresa'])),
            'Campos obligatorios faltantes: '.($requiredMissing !== '' ? $requiredMissing : 'ninguno'),
        ]);

        return implode("\n", $lines);
    }
}
