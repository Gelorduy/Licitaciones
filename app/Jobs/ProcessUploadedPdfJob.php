<?php

namespace App\Jobs;

use App\Models\Acta;
use App\Models\DocumentIndex;
use App\Models\OpinionCumplimiento;
use App\Models\Regulation;
use App\Services\DocumentMetadataExtractor;
use App\Services\DocumentProcessingTraceLogger;
use App\Services\DocumentTextExtractor;
use App\Services\VectorIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessUploadedPdfJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 5400;
    public bool $failOnTimeout = true;

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

        $trace = new DocumentProcessingTraceLogger(
            documentType: $this->documentType,
            documentId: $this->documentId,
            documentClass: $this->documentClass,
            userId: $this->userId,
            companyId: $document instanceof Acta ? $document->company_id : null,
        );

        $trace->record('job.started', 'info', [
            'document_class' => $this->documentClass,
            'document_id' => $this->documentId,
            'document_type' => $this->documentType,
            'disk' => $disk,
            'storage_path' => $path,
        ]);

        $jobStatus = 'completed';
        $jobSummary = [];
        $pendingException = null;

        try {
            $binary = Storage::disk($disk)->get($path);
            $tmp = tempnam(sys_get_temp_dir(), 'pdf_');
            file_put_contents($tmp, $binary);

            $trace->record('file.loaded', 'info', [
                'bytes' => strlen($binary),
                'temp_path' => $tmp,
            ]);

            $extracted = $textExtractor->extract($tmp, [
                'trace' => fn (string $step, array $data = []) => $trace->record($step, 'info', $data),
            ]);
            @unlink($tmp);

            $text = trim((string) ($extracted['text'] ?? ''));
            $indexText = trim((string) ($extracted['index_text'] ?? $text));

            if ($text === '') {
                throw new \RuntimeException('No text extracted from PDF.');
            }

            $chunks = $this->chunkText($text);
            $indexChunks = $this->chunkTextForIndex($indexText);

            $trace->record('text.chunked', 'info', [
                'chunk_count' => count($chunks),
                'index_chunk_count' => count($indexChunks),
                'text_chars' => mb_strlen($text),
                'index_text_chars' => mb_strlen($indexText),
                'extraction_method' => $extracted['method'] ?? 'unknown',
                'vision_page_numbers' => $extracted['vision_page_numbers'] ?? [],
                'vision_first_page_numbers' => $extracted['vision_first_page_numbers'] ?? [],
            ]);

            $metadata = $metadataExtractor->extract($this->documentType, $text, [
                'namespace' => 'licitaciones-'.$this->documentType,
                'document_id' => $this->documentId,
                'document_type' => $this->documentType,
                'user_id' => $this->userId,
                'chunks' => $chunks,
                'vision_pages' => $extracted['vision_pages'] ?? [],
                'vision_page_numbers' => $extracted['vision_page_numbers'] ?? [],
                'vision_first_pages' => $extracted['vision_first_pages'] ?? [],
                'vision_first_page_numbers' => $extracted['vision_first_page_numbers'] ?? [],
                'extraction_method' => (string) ($extracted['method'] ?? 'unknown'),
                'extracted_chars' => (int) ($extracted['chars'] ?? mb_strlen($text)),
                'trace' => fn (string $step, array $data = []) => $trace->record($step, 'info', $data),
            ]);

            $trace->record('metadata.extracted', 'info', [
                'required_missing_fields' => $metadata['required_missing_fields'] ?? [],
                'llm_error' => $metadata['llm_error'] ?? null,
                'llm_engine_used' => $metadata['llm_engine_used'] ?? [],
                'field_sources' => $metadata['field_sources'] ?? [],
            ]);

            $structuredSummary = $this->structuredSummaryForIndex($this->documentType, $metadata);
            if ($structuredSummary !== null) {
                array_unshift($chunks, $structuredSummary);
            }

            $processingTraceReference = $trace->reference();
            $index->update([
                'extraction_method' => (string) ($extracted['method'] ?? 'unknown'),
                'extracted_text' => $text,
                'index_text' => $indexText,
                'metadata' => array_merge($metadata, [
                    'processing_trace' => $processingTraceReference,
                ]),
                'chunk_count' => count($indexChunks),
                'status' => 'processed',
                'error_message' => null,
                'vector_index_error' => null,
            ]);

            $this->applyExtractedMetadata($document, $metadata);
            $document->refresh();

            $trace->record('document.updated', 'info', [
                'persisted_fields' => $document instanceof Acta ? [
                    'fecha_registro' => $document->fecha_registro?->toDateString(),
                    'rpc_fecha_inscripcion' => $document->rpc_fecha_inscripcion?->toDateString(),
                    'fecha_inscripcion' => $document->fecha_inscripcion?->toDateString(),
                    'rpc_folio' => $document->rpc_folio,
                    'rpc_lugar' => $document->rpc_lugar,
                    'notaria_numero' => $document->notaria_numero,
                    'notaria_lugar' => $document->notaria_lugar,
                    'notario_nombre' => $document->notario_nombre,
                    'escritura_numero' => $document->escritura_numero,
                    'libro_numero' => $document->libro_numero,
                    'acto' => $document->acto,
                ] : [],
            ]);

            $jobSummary = [
                'status' => 'processed',
                'text_chars' => mb_strlen($text),
                'chunk_count' => count($indexChunks),
                'missing_fields' => $metadata['required_missing_fields'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('Document text extraction failed', [
                'document_type' => $this->documentType,
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);

            $jobStatus = 'failed';
            $jobSummary = [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];

            $trace->record('job.failed', 'error', [
                'message' => $e->getMessage(),
            ]);

            $index->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'metadata' => array_merge((array) ($index->metadata ?? []), [
                    'processing_trace' => $trace->reference(),
                ]),
            ]);

            $pendingException = $e;
        }

        // Vector indexing is kept in a separate try-catch so an API quota or
        // connectivity error does not mark the document as 'failed'. The
        // extracted text is preserved and the record stays 'processed' so it
        // can be re-queued later via: php artisan documents:requeue-indexing
        if ($pendingException === null) {
            try {
            $trace->record('vector_index.start', 'info', [
                'namespace' => 'licitaciones-'.$this->documentType,
                'base_id' => $this->documentType.'-'.$this->documentId,
                'chunk_count' => count($indexChunks),
            ]);

            $indexed = $vectorIndexer->index(
                namespace: 'licitaciones-'.$this->documentType,
                baseId: $this->documentType.'-'.$this->documentId,
                chunks: $indexChunks,
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

                $trace->record('vector_index.completed', 'info', [
                    'indexed' => true,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Vector indexing failed (document stays processed)', [
                'document_type' => $this->documentType,
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);

            $index->update(['vector_index_error' => $e->getMessage()]);
            $trace->record('vector_index.failed', 'warning', [
                'message' => $e->getMessage(),
            ]);
            // Do not re-throw: job completes so it does not enter failed_jobs.
            } 
        }

        $traceReference = $trace->finalize($jobStatus, $jobSummary);

        $index->update([
            'metadata' => array_merge((array) ($index->metadata ?? []), [
                'processing_trace' => $traceReference,
            ]),
        ]);

        if ($pendingException !== null) {
            throw $pendingException;
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

            $scalarFields = [
                'notaria_numero',
                'notaria_lugar',
                'notario_nombre',
                'escritura_numero',
                'libro_numero',
                'rpc_folio',
                'rpc_lugar',
                'rpc_fecha_inscripcion',
                'fecha_registro',
                'fecha_inscripcion',
                'acto',
            ];

            foreach ($scalarFields as $field) {
                $currentValue = $document->{$field} ?? null;
                $incomingValue = $this->normalizeExtractedScalar($metadata[$field] ?? null);

                if ($field === 'acto') {
                    $incomingValue = $this->sanitizeActoIncomingValue($incomingValue);
                }

                if ($incomingValue !== null && $currentValue !== $incomingValue) {
                    $updates[$field] = $incomingValue;
                }
            }

            if (! empty($metadata['apoderados']) && is_array($metadata['apoderados']) && $document->apoderados !== $metadata['apoderados']) {
                $updates['apoderados'] = $metadata['apoderados'];
            }

            if (! empty($metadata['participacion_accionaria']) && is_array($metadata['participacion_accionaria']) && $document->participacion_accionaria !== $metadata['participacion_accionaria']) {
                $updates['participacion_accionaria'] = $metadata['participacion_accionaria'];
            }

            if (! empty($metadata['consejo_administracion']) && is_array($metadata['consejo_administracion']) && $document->consejo_administracion !== $metadata['consejo_administracion']) {
                $updates['consejo_administracion'] = $metadata['consejo_administracion'];
            }

            if (! empty($metadata['direccion_empresa']) && is_array($metadata['direccion_empresa']) && $document->direccion_empresa !== $metadata['direccion_empresa']) {
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

    private function isBlankLike(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        $normalized = Str::lower(trim((string) preg_replace('/\s+/u', ' ', $value)));

        return $normalized === '' || in_array($normalized, [
            'null',
            'n/a',
            'na',
            'none',
            'sin dato',
            'sin datos',
            'no aplica',
            'no disponible',
            'nd',
            'n.d.',
            's/d',
        ], true);
    }

    private function normalizeExtractedScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($this->isBlankLike($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function sanitizeActoIncomingValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($normalized === '' || $this->isBlankLike($normalized)) {
            return null;
        }

        $looksLikeOcrNoise = preg_match('/\b(candidates?|proponer\s+a\s+sus\s+clientes|aptos?\s+de\s+contrataci[oó]n|contrataci[oó]?n?)\b/iu', $normalized) === 1;
        if ($looksLikeOcrNoise) {
            return null;
        }

        $wordCount = count(array_filter(preg_split('/\s+/u', $normalized) ?: []));
        $hasLegalKeyword = preg_match('/\b(constituci[oó]n|asamblea|poder(?:es)?|estatutos|nombramiento|revocaci[oó]n|reforma|protocoli[sz]aci[oó]n|acta constitutiva|administraci[oó]n|apoderad(?:o|os|a|as)|fusi[oó]n|escisi[oó]n|capital|sociedad|otorgamiento)\b/iu', $normalized) === 1;

        if (mb_strlen($normalized) > 160 || ($wordCount > 14 && ! $hasLegalKeyword)) {
            return null;
        }

        return rtrim($normalized, " \t\n\r\0\x0B;,.:-");
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

    private function chunkTextForIndex(string $text, int $chunkSize = 1200): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return [];
        }

        $paragraphs = preg_split('/\n\s*\n/u', $text) ?: [];
        $paragraphs = array_values(array_filter(array_map(static function (string $paragraph): string {
            $lines = preg_split('/\n/u', $paragraph) ?: [];
            $lines = array_values(array_filter(array_map(static fn (string $line): string => trim($line), $lines), static fn (string $line): bool => $line !== ''));

            return implode("\n", $lines);
        }, $paragraphs), static fn (string $paragraph): bool => $paragraph !== ''));

        if ($paragraphs === []) {
            return $this->chunkText($text, $chunkSize);
        }

        $chunks = [];
        $currentChunk = '';

        foreach ($paragraphs as $paragraph) {
            $candidate = $currentChunk === '' ? $paragraph : $currentChunk."\n\n".$paragraph;

            if (mb_strlen($candidate) <= $chunkSize) {
                $currentChunk = $candidate;
                continue;
            }

            if ($currentChunk !== '') {
                $chunks[] = $currentChunk;
            }

            if (mb_strlen($paragraph) <= $chunkSize) {
                $currentChunk = $paragraph;
                continue;
            }

            $paragraphLines = preg_split('/\n/u', $paragraph) ?: [];
            $lineBuffer = '';
            foreach ($paragraphLines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $lineCandidate = $lineBuffer === '' ? $line : $lineBuffer."\n".$line;
                if (mb_strlen($lineCandidate) <= $chunkSize) {
                    $lineBuffer = $lineCandidate;
                    continue;
                }

                if ($lineBuffer !== '') {
                    $chunks[] = $lineBuffer;
                }

                if (mb_strlen($line) <= $chunkSize) {
                    $lineBuffer = $line;
                    continue;
                }

                foreach ($this->chunkText($line, $chunkSize, 0) as $lineChunk) {
                    $chunks[] = $lineChunk;
                }
                $lineBuffer = '';
            }

            $currentChunk = $lineBuffer;
        }

        if ($currentChunk !== '') {
            $chunks[] = $currentChunk;
        }

        return array_values(array_filter(array_map('trim', $chunks)));
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
