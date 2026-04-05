<?php

namespace App\Services;

use App\Services\Contracts\EmbeddingService;
use RuntimeException;

class EmbeddingServiceFactory
{
    /**
     * Create and return the appropriate embedding service based on configuration.
     *
     * @return EmbeddingService
     *
     * @throws RuntimeException If the configured engine is not supported.
     */
    public static function make(): EmbeddingService
    {
        $engine = config('services.embeddings.engine', 'openai');

        return match ($engine) {
            'openai' => new OpenAIEmbeddingService(),
            'ollama' => new OllamaEmbeddingService(),
            default => throw new RuntimeException("Unsupported embedding engine: {$engine}. Supported: openai, ollama."),
        };
    }
}
