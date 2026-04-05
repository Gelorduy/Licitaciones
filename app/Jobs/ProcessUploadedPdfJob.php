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

            $metadata = $metadataExtractor->extract($this->documentType, $text);
            $chunks = $this->chunkText($text);

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
            $document->fill(array_filter([
                'notaria_numero' => $document->notaria_numero ?: ($metadata['notaria_numero'] ?? null),
                'notaria_lugar' => $document->notaria_lugar ?: ($metadata['notaria_lugar'] ?? null),
                'notario_nombre' => $document->notario_nombre ?: ($metadata['notario_nombre'] ?? null),
                'escritura_numero' => $document->escritura_numero ?: ($metadata['escritura_numero'] ?? null),
                'rpc_folio' => $document->rpc_folio ?: ($metadata['rpc_folio'] ?? null),
                'rpc_lugar' => $document->rpc_lugar ?: ($metadata['rpc_lugar'] ?? null),
                'fecha_registro' => $document->fecha_registro ?: ($metadata['fecha_registro'] ?? null),
                'fecha_inscripcion' => $document->fecha_inscripcion ?: ($metadata['fecha_inscripcion'] ?? null),
                'acto' => $document->acto ?: ($metadata['acto'] ?? null),
            ]));
            $document->save();
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
}
