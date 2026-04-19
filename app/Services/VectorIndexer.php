<?php

namespace App\Services;

use App\Services\Contracts\EmbeddingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VectorIndexer
{
    private EmbeddingService $embeddingService;

    public function __construct(?EmbeddingService $embeddingService = null)
    {
        $this->embeddingService = $embeddingService ?? EmbeddingServiceFactory::make();
    }

    public function index(string $namespace, string $baseId, array $chunks, array $metadata = []): bool
    {
        $chunkPayloads = $this->normalizeChunkPayloads($chunks);

        if (count($chunkPayloads) === 0) {
            return false;
        }

        $apiKey = config('services.pinecone.api_key');

        // In local/dev environments use the dev index (e.g. 768-dim for Ollama).
        // In all other environments use the production index.
        $isLocal = app()->environment('local');
        $indexHost = $isLocal
            ? (config('services.pinecone.index_host_dev') ?: config('services.pinecone.index_host'))
            : config('services.pinecone.index_host');

        if (! $apiKey || ! $indexHost) {
            return false;
        }

        $vectors = [];
        $chunkTexts = array_map(static fn (array $chunk): string => $chunk['text'], $chunkPayloads);

        // Batch embed all chunks in one call when supported (Ollama), otherwise
        // fall back to embedding one at a time (OpenAI).
        if ($this->embeddingService instanceof OllamaEmbeddingService) {
            $embeddings = $this->embeddingService->embedBatch($chunkTexts);
            foreach ($chunkPayloads as $i => $chunk) {
                $vectors[] = [
                    'id' => $baseId.'-'.$i,
                    'values' => $embeddings[$i],
                    'metadata' => array_merge($metadata, $chunk['metadata'], [
                        'chunk_index' => $i,
                        'text' => $chunk['text'],
                    ]),
                ];
            }
        } else {
            foreach ($chunkPayloads as $i => $chunk) {
                $vectors[] = [
                    'id' => $baseId.'-'.$i,
                    'values' => $this->embeddingService->embed($chunk['text']),
                    'metadata' => array_merge($metadata, $chunk['metadata'], [
                        'chunk_index' => $i,
                        'text' => $chunk['text'],
                    ]),
                ];
            }
        }

        $response = Http::withHeaders([
            'Api-Key' => $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post(rtrim($indexHost, '/').'/vectors/upsert', [
            'namespace' => $namespace,
            'vectors' => $vectors,
        ]);

        if (! $response->successful()) {
            return false;
        }

        $currentVectorIds = array_values(array_map(static fn (array $vector): string => (string) $vector['id'], $vectors));
        $staleVectorIds = $this->findStaleVectorIds(
            indexHost: $indexHost,
            apiKey: (string) $apiKey,
            namespace: $namespace,
            prefix: $baseId.'-',
            currentVectorIds: $currentVectorIds,
        );

        if ($staleVectorIds === null) {
            return false;
        }

        if ($staleVectorIds !== [] && ! $this->deleteVectors($indexHost, (string) $apiKey, $namespace, $staleVectorIds)) {
            Log::warning('Pinecone stale vector cleanup failed.', [
                'namespace' => $namespace,
                'base_id' => $baseId,
                'stale_vector_count' => count($staleVectorIds),
            ]);

            return false;
        }

        return true;
    }

    private function normalizeChunkPayloads(array $chunks): array
    {
        $payloads = [];

        foreach ($chunks as $chunk) {
            $text = is_array($chunk)
                ? trim((string) ($chunk['text'] ?? ''))
                : trim((string) $chunk);

            if ($text === '') {
                continue;
            }

            $metadata = is_array($chunk) && is_array($chunk['metadata'] ?? null)
                ? $this->normalizeChunkMetadata($chunk['metadata'])
                : [];

            $payloads[] = [
                'text' => $text,
                'metadata' => $metadata,
            ];
        }

        return $payloads;
    }

    private function normalizeChunkMetadata(array $metadata): array
    {
        if (isset($metadata['page_ids'])) {
            if (is_array($metadata['page_ids'])) {
                $metadata['page_ids'] = array_values(array_filter(array_map(static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null, $metadata['page_ids']), static fn (?string $value): bool => is_string($value) && $value !== ''));
                if ($metadata['page_ids'] === []) {
                    unset($metadata['page_ids']);
                }
            } else {
                unset($metadata['page_ids']);
            }
        }

        if (isset($metadata['page_numbers_csv'])) {
            $metadata['page_numbers_csv'] = is_scalar($metadata['page_numbers_csv']) ? trim((string) $metadata['page_numbers_csv']) : null;
            if ($metadata['page_numbers_csv'] === '') {
                unset($metadata['page_numbers_csv']);
            }
        }

        if (isset($metadata['primary_page'])) {
            if (is_numeric($metadata['primary_page'])) {
                $metadata['primary_page'] = (int) $metadata['primary_page'];
            } else {
                unset($metadata['primary_page']);
            }
        }

        foreach ($metadata as $key => $value) {
            if ($value === null) {
                unset($metadata[$key]);
                continue;
            }

            if (is_array($value)) {
                $normalizedList = array_values(array_filter(array_map(static fn (mixed $item): ?string => is_scalar($item) ? trim((string) $item) : null, $value), static fn (?string $item): bool => is_string($item) && $item !== ''));

                if ($normalizedList === []) {
                    unset($metadata[$key]);
                    continue;
                }

                $metadata[$key] = $normalizedList;
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value)) {
                continue;
            }

            if (is_scalar($value)) {
                $normalizedValue = trim((string) $value);
                if ($normalizedValue === '') {
                    unset($metadata[$key]);
                    continue;
                }

                $metadata[$key] = $normalizedValue;
                continue;
            }

            unset($metadata[$key]);
        }

        return $metadata;
    }

    private function findStaleVectorIds(string $indexHost, string $apiKey, string $namespace, string $prefix, array $currentVectorIds): ?array
    {
        $response = Http::withHeaders([
            'Api-Key' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(60)->get(rtrim($indexHost, '/').'/vectors/list', [
            'namespace' => $namespace,
            'prefix' => $prefix,
        ]);

        if (! $response->successful()) {
            Log::warning('Pinecone vector list failed during stale cleanup.', [
                'namespace' => $namespace,
                'prefix' => $prefix,
                'status' => $response->status(),
            ]);

            return null;
        }

        $existingVectorIds = collect(data_get($response->json(), 'vectors', []))
            ->map(static fn ($item): ?string => is_array($item) ? ($item['id'] ?? null) : null)
            ->filter(static fn ($id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        if ($existingVectorIds === []) {
            return [];
        }

        return array_values(array_diff($existingVectorIds, $currentVectorIds));
    }

    private function deleteVectors(string $indexHost, string $apiKey, string $namespace, array $vectorIds): bool
    {
        foreach (array_chunk($vectorIds, 100) as $batch) {
            $response = Http::withHeaders([
                'Api-Key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(60)->post(rtrim($indexHost, '/').'/vectors/delete', [
                'namespace' => $namespace,
                'ids' => array_values($batch),
            ]);

            if (! $response->successful()) {
                Log::warning('Pinecone vector delete failed during stale cleanup.', [
                    'namespace' => $namespace,
                    'status' => $response->status(),
                    'batch_size' => count($batch),
                ]);

                return false;
            }
        }

        return true;
    }
}
