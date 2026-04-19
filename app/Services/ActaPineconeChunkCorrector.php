<?php

namespace App\Services;

use App\Models\Acta;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ActaPineconeChunkCorrector
{
    public function __construct(
        private readonly DocumentTextExtractor $documentTextExtractor,
        private readonly DocumentChunker $documentChunker,
        private readonly VectorIndexer $vectorIndexer,
    ) {
    }

    public function correctChunk(Acta $acta, int $chunkIndex): array
    {
        $index = $acta->documentIndex;
        if (! $index) {
            throw new RuntimeException('La acta todavía no tiene un DocumentIndex para corregir chunks.');
        }

        $metadata = is_array($index->metadata) ? $index->metadata : [];
        $chunkPayloads = $metadata['index_chunk_payloads'] ?? null;

        if (! is_array($chunkPayloads) || $chunkPayloads === []) {
            throw new RuntimeException('No hay chunks persistidos para corrección localizada. Reextrae el documento primero.');
        }

        if (! array_key_exists($chunkIndex, $chunkPayloads) || ! is_array($chunkPayloads[$chunkIndex])) {
            throw new RuntimeException('El chunk solicitado no existe en este documento.');
        }

        $chunkPayload = $chunkPayloads[$chunkIndex];
        $currentText = trim((string) ($chunkPayload['text'] ?? ''));
        if ($currentText === '') {
            throw new RuntimeException('El chunk solicitado no contiene texto para corregir.');
        }

        $pageNumbers = $this->normalizePageNumbers(
            $chunkPayload['page_numbers']
                ?? data_get($metadata, 'index_chunk_page_map.'.$chunkIndex.'.page_numbers', []),
        );

        if ($pageNumbers === []) {
            throw new RuntimeException('El chunk solicitado no tiene páginas fuente asociadas.');
        }

        [$disk, $path, $sourceFileAvailable] = $this->resolveStorageInfo($acta);
        if (! $path || ! $sourceFileAvailable) {
            throw new RuntimeException('Source PDF not found on configured storage disks: '.($path ?: 'n/a'));
        }

        $binary = Storage::disk($disk)->get($path);
        $tmp = tempnam(sys_get_temp_dir(), 'pdf_');
        file_put_contents($tmp, $binary);

        try {
            $correction = null;
            $visionFailure = null;

            try {
                $pageImagesPayload = $this->documentTextExtractor->extractPageImages($tmp, $pageNumbers);
                $pageImages = $pageImagesPayload['page_images'] ?? [];

                if (is_array($pageImages) && $pageImages !== []) {
                    try {
                        $correction = $this->correctChunkWithVision($pageImages, $currentText, $pageNumbers);
                    } catch (\Throwable $e) {
                        $visionFailure = $e->getMessage();
                    }
                } else {
                    $visionFailure = 'No se pudieron extraer imágenes de las páginas del chunk.';
                }
            } catch (\Throwable $e) {
                $visionFailure = $e->getMessage();
            }

            if ($correction === null) {
                try {
                    $correction = $this->correctChunkWithTargetedOcr($tmp, $chunkPayloads, $chunkIndex, $currentText, $pageNumbers, $visionFailure);
                } catch (\Throwable $ocrException) {
                    if ($visionFailure !== null) {
                        throw new RuntimeException($visionFailure.' Además, el OCR dirigido falló: '.$ocrException->getMessage());
                    }

                    throw $ocrException;
                }
            }
        } finally {
            @unlink($tmp);
        }

        $correctedText = trim((string) data_get($correction, 'data.corrected_text', ''));
        if ($correctedText === '') {
            throw new RuntimeException('La corrección devolvió texto vacío.');
        }

        $this->validateCorrectionBounds($currentText, $correctedText);

        $correctionEngine = data_get($correction, 'meta.engine', 'ollama-vision');

        if ($correctedText === $currentText) {
            return [
                'changed' => false,
                'indexed' => true,
                'chunkIndex' => $chunkIndex,
                'pageNumbers' => $pageNumbers,
                'previousText' => $currentText,
                'correctedText' => $correctedText,
                'engine' => $correctionEngine,
            ];
        }

        $chunkMetadata = is_array($chunkPayload['metadata'] ?? null) ? $chunkPayload['metadata'] : [];
        $updatedAt = now()->toIso8601String();
        $chunkPayloads[$chunkIndex] = [
            'text' => $correctedText,
            'page_numbers' => $pageNumbers,
            'metadata' => array_merge($chunkMetadata, [
                'page_ids' => array_map(static fn (int $pageNumber): string => 'page-'.$pageNumber, $pageNumbers),
                'page_numbers_csv' => implode(',', $pageNumbers),
                'primary_page' => $pageNumbers[0] ?? null,
                'corrected_with_vision' => $correctionEngine === 'ollama-vision',
                'corrected_with_targeted_ocr' => $correctionEngine === 'targeted-ocr',
                'correction_updated_at' => $updatedAt,
                'correction_engine' => $correctionEngine,
                'correction_page_numbers_csv' => implode(',', $pageNumbers),
                'correction_reason' => $correctionEngine === 'ollama-vision' ? 'manual_chunk_vision' : 'manual_chunk_targeted_ocr',
                'correction_fallback_from' => data_get($correction, 'meta.fallback_from'),
                'correction_fallback_error' => data_get($correction, 'meta.fallback_error'),
            ]),
        ];

        $metadata['index_chunk_payloads'] = array_values($chunkPayloads);
        $metadata['index_chunk_page_map'] = $this->documentChunker->exportChunkPageMap($metadata['index_chunk_payloads']);

        $correctionHistory = is_array($metadata['chunk_corrections'] ?? null) ? $metadata['chunk_corrections'] : [];
        $correctionHistory[] = [
            'chunk_index' => $chunkIndex,
            'page_numbers' => $pageNumbers,
            'previous_text_preview' => Str::limit($currentText, 220),
            'corrected_text_preview' => Str::limit($correctedText, 220),
            'engine' => $correctionEngine,
            'updated_at' => $updatedAt,
        ];
        $metadata['chunk_corrections'] = array_slice($correctionHistory, -50);

        $index->update([
            'metadata' => $metadata,
            'chunk_count' => count($metadata['index_chunk_payloads']),
            'status' => 'processed',
            'vector_index_error' => null,
        ]);

        $indexed = $this->vectorIndexer->index(
            namespace: 'licitaciones-'.$index->document_type,
            baseId: $index->document_type.'-'.$index->documentable_id,
            chunks: $metadata['index_chunk_payloads'],
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
        } else {
            $index->update([
                'status' => 'processed',
                'vector_index_error' => 'La corrección de chunk se guardó, pero Pinecone no pudo reindexarse automáticamente.',
            ]);
        }

        return [
            'changed' => true,
            'indexed' => $indexed,
            'chunkIndex' => $chunkIndex,
            'pageNumbers' => $pageNumbers,
            'previousText' => $currentText,
            'correctedText' => $correctedText,
            'engine' => $correctionEngine,
        ];
    }

    private function resolveStorageInfo(Acta $acta): array
    {
        $path = $acta->documento_path;

        if (! $path) {
            return ['s3', null, false];
        }

        try {
            if (Storage::disk('s3')->exists($path)) {
                return ['s3', $path, true];
            }
        } catch (\Throwable) {
        }

        try {
            if (Storage::disk('public')->exists($path)) {
                return ['public', $path, true];
            }
        } catch (\Throwable) {
        }

        return ['s3', $path, false];
    }

    private function correctChunkWithVision(array $visionPages, string $currentText, array $pageNumbers): array
    {
        if (! filter_var(config('services.ollama.vision_enabled', true), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('La visión con Ollama está deshabilitada en configuración.');
        }

        $images = array_values(array_filter($visionPages, static fn ($item): bool => is_string($item) && trim($item) !== ''));
        if ($images === []) {
            throw new RuntimeException('No hay imágenes disponibles para corrección por visión.');
        }

        $model = config('services.ollama.vision_model', 'qwen2.5vl:7b');
        $baseUrl = config('services.ollama.base_url', 'http://ollama:11434');
        $timeout = max(8, min((int) config('services.ollama.vision_timeout', 120), 20));
        $totalBudgetMs = min(max((int) config('services.ollama.vision_total_budget_ms', 95000), 5000), 22000);
        $maxAttempts = 1;
        $baseDelayMs = max((int) config('services.ollama.vision_retry_base_delay_ms', 1200), 100);

        $prompt = "Analiza estas imágenes de páginas exactas de un acta corporativa mexicana.\n"
            ."Objetivo: corregir el fragmento OCR actual usando SOLO evidencia visible en las imágenes.\n"
            ."Devuelve únicamente una versión corregida del mismo tramo documental; no resumas, no inventes y no devuelvas la página completa si el fragmento original representa solo una parte.\n"
            ."Conserva saltos de línea razonables y mejora acentos o palabras rotas solo cuando sea evidente por evidencia visual.\n"
            ."Convenciones notariales/OCR: ".$this->actaOcrInterpretationHint()."\n"
            ."Páginas fuente: ".implode(', ', $pageNumbers)."\n\n"
            ."Fragmento OCR actual:\n<<<\n".$currentText."\n>>>\n\n"
            .'Devuelve SOLO un JSON válido con esta forma: {"corrected_text": "texto corregido"}';

        $lastError = null;
        $startedAt = microtime(true);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $elapsedMs = (int) floor((microtime(true) - $startedAt) * 1000);
            $remainingMs = $totalBudgetMs - $elapsedMs;

            if ($remainingMs <= 0) {
                $lastError = 'vision_budget_exhausted';
                break;
            }

            $requestTimeoutSec = max(5, min($timeout, (int) ceil($remainingMs / 1000)));

            try {
                $response = Http::timeout($requestTimeoutSec)->post(rtrim($baseUrl, '/').'/api/generate', [
                    'model' => $model,
                    'prompt' => $prompt,
                    'images' => $images,
                    'stream' => false,
                    'format' => 'json',
                    'options' => [
                        'temperature' => 0,
                    ],
                ]);

                if (! $response->successful()) {
                    $lastError = 'http_'.$response->status();
                } else {
                    $raw = data_get($response->json(), 'response');
                    if (! is_string($raw) || trim($raw) === '') {
                        $lastError = 'empty_response';
                    } else {
                        $json = json_decode($raw, true);
                        if (! is_array($json)) {
                            $lastError = 'invalid_json';
                        } else {
                            return [
                                'data' => [
                                    'corrected_text' => trim((string) ($json['corrected_text'] ?? '')),
                                ],
                                'meta' => [
                                    'engine' => 'ollama-vision',
                                    'attempts' => $attempt,
                                    'images_used' => count($images),
                                    'elapsed_ms' => (int) floor((microtime(true) - $startedAt) * 1000),
                                    'error' => null,
                                ],
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                $lastError = 'exception: '.$e->getMessage();
                if ($this->isTimeoutError($e->getMessage())) {
                    break;
                }
            }

            if ($attempt < $maxAttempts) {
                $delayMs = $baseDelayMs * (2 ** ($attempt - 1));
                $elapsedAfterAttemptMs = (int) floor((microtime(true) - $startedAt) * 1000);
                if (($totalBudgetMs - $elapsedAfterAttemptMs) <= $delayMs) {
                    $lastError = $lastError ?? 'vision_budget_exhausted';
                    break;
                }

                usleep($delayMs * 1000);
            }
        }

        throw new RuntimeException('La corrección por visión falló: '.($lastError ?? 'vision_failed'));
    }

    private function correctChunkWithTargetedOcr(
        string $pdfPath,
        array $chunkPayloads,
        int $chunkIndex,
        string $currentText,
        array $pageNumbers,
        ?string $visionFailure,
    ): array {
        $ocrPayload = $this->documentTextExtractor->extractPageOcr($pdfPath, $pageNumbers);
        $indexPages = is_array($ocrPayload['index_pages'] ?? null) ? $ocrPayload['index_pages'] : [];
        if ($indexPages === []) {
            throw new RuntimeException('El OCR dirigido no produjo páginas indexables para el chunk solicitado.');
        }

        $indexText = implode("\n\n", array_values(array_filter(array_map(static fn (array $page): string => is_string($page['text'] ?? null) ? trim($page['text']) : '', $indexPages), static fn (string $text): bool => $text !== '')));
        $candidatePayloads = $this->documentChunker->chunkTextForIndexWithPages($indexText, $indexPages);
        if ($candidatePayloads === []) {
            throw new RuntimeException('El OCR dirigido no produjo chunks candidatos para el rango de páginas solicitado.');
        }

        [$bestCandidate, $bestCandidateIndex, $bestSimilarity] = $this->selectBestTargetedOcrCandidate(
            $candidatePayloads,
            $chunkPayloads,
            $chunkIndex,
            $currentText,
            $pageNumbers,
        );

        $correctedText = trim((string) ($bestCandidate['text'] ?? ''));
        if ($correctedText === '') {
            throw new RuntimeException('El OCR dirigido no encontró un candidato utilizable para corregir el chunk.');
        }

        return [
            'data' => [
                'corrected_text' => $correctedText,
            ],
            'meta' => [
                'engine' => 'targeted-ocr',
                'attempts' => 1,
                'candidate_index' => $bestCandidateIndex,
                'candidate_count' => count($candidatePayloads),
                'similarity_percent' => round($bestSimilarity, 2),
                'fallback_from' => $visionFailure ? 'ollama-vision' : null,
                'fallback_error' => $visionFailure,
                'error' => null,
            ],
        ];
    }

    private function selectBestTargetedOcrCandidate(
        array $candidatePayloads,
        array $chunkPayloads,
        int $chunkIndex,
        string $currentText,
        array $pageNumbers,
    ): array {
        $currentComparable = $this->normalizeSimilarityText($currentText);
        $relatedExistingChunkIndexes = [];

        foreach ($chunkPayloads as $existingIndex => $chunkPayload) {
            if (! is_array($chunkPayload)) {
                continue;
            }

            $existingPages = $this->normalizePageNumbers($chunkPayload['page_numbers'] ?? []);
            if (array_intersect($pageNumbers, $existingPages) !== []) {
                $relatedExistingChunkIndexes[] = $existingIndex;
            }
        }

        $targetRelativePosition = array_search($chunkIndex, $relatedExistingChunkIndexes, true);
        $bestCandidate = null;
        $bestCandidateIndex = 0;
        $bestScore = -INF;
        $bestSimilarity = 0.0;

        foreach ($candidatePayloads as $candidateIndex => $candidatePayload) {
            if (! is_array($candidatePayload)) {
                continue;
            }

            $candidateText = trim((string) ($candidatePayload['text'] ?? ''));
            if ($candidateText === '') {
                continue;
            }

            similar_text($currentComparable, $this->normalizeSimilarityText($candidateText), $similarityPercent);
            $candidatePages = $this->normalizePageNumbers($candidatePayload['page_numbers'] ?? []);
            $pageOverlap = count(array_intersect($pageNumbers, $candidatePages));
            $candidateLength = max(mb_strlen($candidateText), 1);
            $currentLength = max(mb_strlen($currentText), 1);
            $lengthRatio = min($candidateLength, $currentLength) / max($candidateLength, $currentLength);
            $positionPenalty = $targetRelativePosition === false ? 0 : abs($candidateIndex - $targetRelativePosition) * 8;
            $score = $similarityPercent + ($pageOverlap * 12) + ($lengthRatio * 18) - $positionPenalty;

            if ($bestCandidate === null || $score > $bestScore) {
                $bestCandidate = $candidatePayload;
                $bestCandidateIndex = $candidateIndex;
                $bestScore = $score;
                $bestSimilarity = $similarityPercent;
            }
        }

        if ($bestCandidate === null) {
            throw new RuntimeException('El OCR dirigido no produjo ningún candidato comparable.');
        }

        return [$bestCandidate, $bestCandidateIndex, $bestSimilarity];
    }

    private function validateCorrectionBounds(string $currentText, string $correctedText): void
    {
        $currentLength = max(mb_strlen($currentText), 1);
        $correctedLength = mb_strlen($correctedText);
        $minLength = max(40, (int) floor($currentLength * 0.30));
        $maxLength = max(1800, (int) ceil($currentLength * 2.20));

        if ($correctedLength < $minLength) {
            throw new RuntimeException('La corrección devolvió un texto demasiado corto para el chunk solicitado.');
        }

        if ($correctedLength > $maxLength) {
            throw new RuntimeException('La corrección devolvió demasiado texto y parece fuera del chunk solicitado.');
        }
    }

    private function normalizeSimilarityText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function normalizePageNumbers(mixed $pageNumbers): array
    {
        if (! is_array($pageNumbers)) {
            return [];
        }

        $normalized = [];
        foreach ($pageNumbers as $pageNumber) {
            if (! is_numeric($pageNumber)) {
                continue;
            }

            $pageNumber = (int) $pageNumber;
            if ($pageNumber <= 0 || in_array($pageNumber, $normalized, true)) {
                continue;
            }

            $normalized[] = $pageNumber;
        }

        return $normalized;
    }

    private function actaOcrInterpretationHint(): string
    {
        return 'En estos documentos notariales, dos o más guiones consecutivos dentro de una línea suelen representar espacios en blanco; si la secuencia termina una línea, interprétala como salto de línea o continuación, no como texto literal. El carácter ~ dentro de una palabra OCR suele sustituir una vocal acentuada; reconstruye la palabra solo cuando sea evidente por el contexto visual inmediato.';
    }

    private function isTimeoutError(string $message): bool
    {
        $lower = mb_strtolower($message);

        return str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
            || str_contains($lower, 'curl error 28');
    }
}