<?php

namespace App\Jobs;

use App\Models\DocumentIndex;
use App\Services\DocumentChunker;
use App\Services\VectorIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Indexes an already-extracted document into the vector store.
 *
 * Unlike ProcessUploadedPdfJob this job does NOT re-run OCR or text
 * extraction. It reads the already-stored extracted_text from the
 * document_indexes table and sends the chunks to Pinecone.
 *
 * Use this when a document has status='processed' but is not yet
 * vector-indexed, e.g. after enabling Pinecone for the first time or
 * switching embedding engines.
 */
class VectorIndexDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $documentIndexId)
    {
    }

    public function handle(VectorIndexer $vectorIndexer, DocumentChunker $documentChunker): void
    {
        $index = DocumentIndex::find($this->documentIndexId);

        if (! $index) {
            return;
        }

        $text = trim((string) ($index->index_text ?? $index->extracted_text ?? ''));

        if ($text === '') {
            Log::warning('VectorIndexDocumentJob: no extracted text, skipping.', ['id' => $this->documentIndexId]);

            return;
        }

        try {
            $storedChunkPayloads = data_get($index->metadata, 'index_chunk_payloads');
            if (is_array($storedChunkPayloads) && $storedChunkPayloads !== []) {
                $chunkPayloads = $storedChunkPayloads;
            } else {
                $chunks = $documentChunker->chunkTextForIndex($text);
                $chunkPageMap = is_array(data_get($index->metadata, 'index_chunk_page_map'))
                    ? data_get($index->metadata, 'index_chunk_page_map')
                    : [];
                $chunkPayloads = $documentChunker->attachStoredPageMap($chunks, $chunkPageMap);
            }

            $indexed = $vectorIndexer->index(
                namespace: 'licitaciones-'.$index->document_type,
                baseId: $index->document_type.'-'.$index->documentable_id,
                chunks: $chunkPayloads,
                metadata: [
                    'document_type' => $index->document_type,
                    'document_id' => $index->documentable_id,
                    'storage_path' => $index->storage_path,
                    'user_id' => $index->user_id,
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
            Log::warning('VectorIndexDocumentJob: vector indexing failed.', [
                'id' => $this->documentIndexId,
                'error' => $e->getMessage(),
            ]);

            $index->update(['vector_index_error' => $e->getMessage()]);
            // Do not re-throw so the record stays 'processed' rather than entering failed_jobs.
        }
    }
}
