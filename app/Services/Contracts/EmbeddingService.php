<?php

namespace App\Services\Contracts;

interface EmbeddingService
{
    /**
     * Embed a text string into a vector.
     *
     * @param  string  $text  The text to embed.
     * @return array The embedding vector as a numeric array.
     *
     * @throws \RuntimeException If embedding fails.
     */
    public function embed(string $text): array;
}
