<?php

namespace App\Services;

use App\Services\Contracts\EmbeddingService;
use Illuminate\Support\Facades\Http;

class VectorIndexer
{
    private EmbeddingService $embeddingService;

    public function __construct(EmbeddingService $embeddingService = null)
    {
        $this->embeddingService = $embeddingService ?? EmbeddingServiceFactory::make();
    }

    public function index(string $namespace, string $baseId, array $chunks, array $metadata = []): bool
    {
        if (count($chunks) === 0) {
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

        // Batch embed all chunks in one call when supported (Ollama), otherwise
        // fall back to embedding one at a time (OpenAI).
        if ($this->embeddingService instanceof OllamaEmbeddingService) {
            $embeddings = $this->embeddingService->embedBatch(array_values($chunks));
            foreach ($chunks as $i => $chunk) {
                $vectors[] = [
                    'id' => $baseId.'-'.$i,
                    'values' => $embeddings[$i],
                    'metadata' => array_merge($metadata, [
                        'chunk_index' => $i,
                        'text' => $chunk,
                    ]),
                ];
            }
        } else {
            foreach ($chunks as $i => $chunk) {
                $vectors[] = [
                    'id' => $baseId.'-'.$i,
                    'values' => $this->embeddingService->embed($chunk),
                    'metadata' => array_merge($metadata, [
                        'chunk_index' => $i,
                        'text' => $chunk,
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

        return $response->successful();
    }
}
