<?php

namespace App\Services;

use App\Services\Contracts\EmbeddingService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIEmbeddingService implements EmbeddingService
{
    public function embed(string $text): array
    {
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => config('services.openai.embedding_model', 'text-embedding-3-small'),
                'input' => $text,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI embeddings call failed: '.$response->body());
        }

        $embedding = data_get($response->json(), 'data.0.embedding');

        if (! is_array($embedding)) {
            throw new RuntimeException('Embedding response did not contain vector values.');
        }

        return $embedding;
    }
}
