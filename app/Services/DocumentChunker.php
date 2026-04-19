<?php

namespace App\Services;

class DocumentChunker
{
    public function chunkText(string $text, int $chunkSize = 1200, int $overlap = 150): array
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $length = mb_strlen($text);

        if ($length === 0) {
            return [];
        }

        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $chunk = trim(mb_substr($text, $start, $chunkSize));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            $start += max($chunkSize - $overlap, 1);
        }

        return $chunks;
    }

    public function chunkTextForIndex(string $text, int $chunkSize = 1200): array
    {
        $chunks = [];
        foreach ($this->chunkTextForIndexWithPages($text, [], $chunkSize) as $chunk) {
            $chunkText = trim((string) ($chunk['text'] ?? ''));
            if ($chunkText !== '') {
                $chunks[] = $chunkText;
            }
        }

        return $chunks;
    }

    public function chunkTextForIndexWithPages(string $text, array $indexPages, int $chunkSize = 1200): array
    {
        $segments = [];

        foreach ($indexPages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $pageNumber = is_numeric($page['page_number'] ?? null) ? (int) $page['page_number'] : null;
            $pageText = is_string($page['text'] ?? null)
                ? trim(str_replace(["\r\n", "\r"], "\n", $page['text']))
                : '';

            if (! $pageNumber || $pageText === '') {
                continue;
            }

            foreach ($this->splitIndexParagraphs($pageText) as $paragraph) {
                foreach ($this->splitOversizedParagraph($paragraph, $chunkSize) as $piece) {
                    $segments[] = [
                        'text' => $piece,
                        'page_numbers' => [$pageNumber],
                    ];
                }
            }
        }

        if ($segments === []) {
            $fallback = trim(str_replace(["\r\n", "\r"], "\n", $text));
            if ($fallback === '') {
                return [];
            }

            $paragraphs = $this->splitIndexParagraphs($fallback);
            if ($paragraphs === []) {
                return array_map(fn (string $chunk): array => $this->makeChunkPayload($chunk, []), $this->chunkText($fallback, $chunkSize));
            }

            $segments = array_map(static fn (string $paragraph): array => [
                'text' => $paragraph,
                'page_numbers' => [],
            ], $paragraphs);
        }

        $chunks = [];
        $currentText = '';
        $currentPages = [];

        foreach ($segments as $segment) {
            $segmentText = trim((string) ($segment['text'] ?? ''));
            $segmentPages = $this->normalizePageNumbers($segment['page_numbers'] ?? []);

            if ($segmentText === '') {
                continue;
            }

            $candidate = $currentText === '' ? $segmentText : $currentText."\n\n".$segmentText;

            if (mb_strlen($candidate) <= $chunkSize) {
                $currentText = $candidate;
                $currentPages = $this->normalizePageNumbers(array_merge($currentPages, $segmentPages));
                continue;
            }

            if ($currentText !== '') {
                $chunks[] = $this->makeChunkPayload($currentText, $currentPages);
            }

            $currentText = $segmentText;
            $currentPages = $segmentPages;
        }

        if ($currentText !== '') {
            $chunks[] = $this->makeChunkPayload($currentText, $currentPages);
        }

        return array_values(array_filter($chunks, static fn (array $chunk): bool => trim((string) ($chunk['text'] ?? '')) !== ''));
    }

    public function exportChunkPageMap(array $chunkPayloads): array
    {
        $map = [];

        foreach ($chunkPayloads as $index => $chunkPayload) {
            if (! is_array($chunkPayload)) {
                continue;
            }

            $pageNumbers = $this->normalizePageNumbers($chunkPayload['page_numbers'] ?? []);
            $map[] = [
                'chunk_index' => $index,
                'page_numbers' => $pageNumbers,
                'page_ids' => array_map(static fn (int $pageNumber): string => 'page-'.$pageNumber, $pageNumbers),
            ];
        }

        return $map;
    }

    public function attachStoredPageMap(array $chunks, array $chunkPageMap): array
    {
        $pageLookup = [];

        foreach ($chunkPageMap as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $chunkIndex = is_numeric($entry['chunk_index'] ?? null) ? (int) $entry['chunk_index'] : null;
            if ($chunkIndex === null) {
                continue;
            }

            $pageLookup[$chunkIndex] = $this->normalizePageNumbers($entry['page_numbers'] ?? []);
        }

        $payloads = [];
        foreach ($chunks as $index => $chunkText) {
            $payloads[] = $this->makeChunkPayload((string) $chunkText, $pageLookup[$index] ?? []);
        }

        return array_values(array_filter($payloads, static fn (array $chunk): bool => trim((string) ($chunk['text'] ?? '')) !== ''));
    }

    private function splitIndexParagraphs(string $text): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return [];
        }

        $paragraphs = preg_split('/\n\s*\n/u', $text) ?: [];

        return array_values(array_filter(array_map(static function (string $paragraph): string {
            $lines = preg_split('/\n/u', $paragraph) ?: [];
            $lines = array_values(array_filter(array_map(static fn (string $line): string => trim($line), $lines), static fn (string $line): bool => $line !== ''));

            return implode("\n", $lines);
        }, $paragraphs), static fn (string $paragraph): bool => $paragraph !== ''));
    }

    private function splitOversizedParagraph(string $paragraph, int $chunkSize): array
    {
        $paragraph = trim($paragraph);

        if ($paragraph === '') {
            return [];
        }

        if (mb_strlen($paragraph) <= $chunkSize) {
            return [$paragraph];
        }

        $chunks = [];
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

        if ($lineBuffer !== '') {
            $chunks[] = $lineBuffer;
        }

        return array_values(array_filter(array_map('trim', $chunks)));
    }

    private function makeChunkPayload(string $text, array $pageNumbers): array
    {
        $pageNumbers = $this->normalizePageNumbers($pageNumbers);
        $pageIds = array_map(static fn (int $pageNumber): string => 'page-'.$pageNumber, $pageNumbers);

        return [
            'text' => trim($text),
            'page_numbers' => $pageNumbers,
            'metadata' => array_filter([
                'page_ids' => $pageIds === [] ? null : $pageIds,
                'page_numbers_csv' => $pageNumbers === [] ? null : implode(',', $pageNumbers),
                'primary_page' => $pageNumbers[0] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        ];
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
}