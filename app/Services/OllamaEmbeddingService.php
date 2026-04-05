<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaEmbeddingService implements Contracts\EmbeddingService
{
    public function __construct(
        private string $baseUrl = 'http://ollama:11434'
    ) {
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    /**
     * Embed multiple texts in a single API call (Ollama /api/embed batch endpoint).
     *
     * @param  string[]  $texts
     * @return array[]  Array of embedding vectors, one per input text.
     */
    public function embedBatch(array $texts): array
    {
        $model = config('services.ollama.model', 'nomic-embed-text');
        $baseUrl = config('services.ollama.base_url', $this->baseUrl);

        if (empty($texts)) {
            return [];
        }

        try {
            $response = Http::timeout(300)
                ->post(rtrim($baseUrl, '/').'/api/embed', [
                    'model' => $model,
                    'input' => $texts,
                ]);

            if ($response->failed()) {
                throw new RuntimeException('Ollama batch embed failed: '.$response->body());
            }

            $embeddings = data_get($response->json(), 'embeddings');

            if (! is_array($embeddings) || count($embeddings) !== count($texts)) {
                throw new RuntimeException('Ollama batch embed returned unexpected number of embeddings.');
            }

            return $embeddings;
        } catch (\Throwable $e) {
            throw new RuntimeException('Ollama batch embedding failed: '.$e->getMessage(), 0, $e);
        }
    }
}
