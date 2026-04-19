<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class DocumentTextExtractor
{
    public function extract(string $pdfPath, array $options = []): array
    {
        $script = base_path('scripts/pdf_extract.py');
        $timeoutSeconds = 240;

        if (! file_exists($script)) {
            throw new RuntimeException('OCR script not found at '.$script);
        }

        $command = [
            'python3',
            $script,
            $pdfPath,
            config('services.ocr.languages', 'spa+eng'),
            (string) max((int) config('services.ocr.vision_pages', 3), 0),
            (string) max((int) config('services.ocr.vision_scan_pages', 8), 1),
            (string) max((int) config('services.ocr.vision_max_width', 1400), 800),
            (string) max((int) config('services.ocr.vision_quality', 70), 40),
        ];

        $this->trace($options, 'ocr.process.start', [
            'script' => $script,
            'pdf_path' => $pdfPath,
            'command' => $command,
            'timeout_seconds' => $timeoutSeconds,
        ]);

        $process = new Process($command);

        $process->setTimeout($timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->trace($options, 'ocr.process.failed', [
                'exit_code' => $process->getExitCode(),
                'error_output' => trim($process->getErrorOutput()),
                'output_preview' => mb_substr(trim($process->getOutput()), 0, 2000),
            ]);

            throw new RuntimeException('PDF extraction failed: '.trim($process->getErrorOutput().' '.$process->getOutput()));
        }

        $payload = json_decode($process->getOutput(), true);

        if (! is_array($payload) || ! isset($payload['text'])) {
            Log::warning('Unexpected OCR output', ['output' => $process->getOutput()]);
            $this->trace($options, 'ocr.process.invalid_output', [
                'output_preview' => mb_substr($process->getOutput(), 0, 4000),
            ]);
            throw new RuntimeException('Unexpected OCR output format.');
        }

        $visionPages = [];
        if (isset($payload['vision_pages']) && is_array($payload['vision_pages'])) {
            $visionPages = array_values(array_filter($payload['vision_pages'], static fn ($item) => is_string($item) && trim($item) !== ''));
        }

        $visionPageNumbers = [];
        if (isset($payload['vision_page_numbers']) && is_array($payload['vision_page_numbers'])) {
            $visionPageNumbers = array_values(array_filter(array_map(static fn ($item) => is_numeric($item) ? (int) $item : null, $payload['vision_page_numbers']), static fn ($item) => is_int($item) && $item > 0));
        }

        $visionFirstPages = [];
        if (isset($payload['vision_first_pages']) && is_array($payload['vision_first_pages'])) {
            $visionFirstPages = array_values(array_filter($payload['vision_first_pages'], static fn ($item) => is_string($item) && trim($item) !== ''));
        }

        $visionFirstPageNumbers = [];
        if (isset($payload['vision_first_page_numbers']) && is_array($payload['vision_first_page_numbers'])) {
            $visionFirstPageNumbers = array_values(array_filter(array_map(static fn ($item) => is_numeric($item) ? (int) $item : null, $payload['vision_first_page_numbers']), static fn ($item) => is_int($item) && $item > 0));
        }

        $indexPages = [];
        if (isset($payload['index_pages']) && is_array($payload['index_pages'])) {
            foreach ($payload['index_pages'] as $page) {
                if (! is_array($page)) {
                    continue;
                }

                $pageNumber = is_numeric($page['page_number'] ?? null) ? (int) $page['page_number'] : null;
                if (! $pageNumber || $pageNumber <= 0) {
                    continue;
                }

                $indexPages[] = [
                    'page_number' => $pageNumber,
                    'text' => is_string($page['text'] ?? null) ? trim($page['text']) : '',
                ];
            }
        }

        $payload['vision_pages'] = $visionPages;
        $payload['vision_page_numbers'] = $visionPageNumbers;
        $payload['vision_first_pages'] = $visionFirstPages;
        $payload['vision_first_page_numbers'] = $visionFirstPageNumbers;
        $payload['index_pages'] = $indexPages;

        $this->trace($options, 'ocr.process.completed', [
            'extraction_method' => $payload['method'] ?? null,
            'chars' => $payload['chars'] ?? mb_strlen((string) ($payload['text'] ?? '')),
            'index_pages_count' => count($indexPages),
            'vision_pages_count' => count($visionPages),
            'vision_page_numbers' => $visionPageNumbers,
            'vision_first_pages_count' => count($visionFirstPages),
            'vision_first_page_numbers' => $visionFirstPageNumbers,
        ]);

        return $payload;
    }

    public function extractPageImages(string $pdfPath, array $pageNumbers, array $options = []): array
    {
        $script = base_path('scripts/pdf_page_images.py');
        $timeoutSeconds = 120;
        $pageNumbers = array_values(array_unique(array_filter(array_map(static fn ($item) => is_numeric($item) ? (int) $item : null, $pageNumbers), static fn ($item) => is_int($item) && $item > 0)));

        if ($pageNumbers === []) {
            return [
                'page_images' => [],
                'page_numbers' => [],
            ];
        }

        if (! file_exists($script)) {
            throw new RuntimeException('Page image extraction script not found at '.$script);
        }

        $command = [
            'python3',
            $script,
            $pdfPath,
            implode(',', $pageNumbers),
            (string) max((int) config('services.ocr.vision_max_width', 1400), 800),
            (string) max((int) config('services.ocr.vision_quality', 70), 40),
        ];

        $this->trace($options, 'ocr.page_images.start', [
            'script' => $script,
            'pdf_path' => $pdfPath,
            'page_numbers' => $pageNumbers,
            'command' => $command,
            'timeout_seconds' => $timeoutSeconds,
        ]);

        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->trace($options, 'ocr.page_images.failed', [
                'exit_code' => $process->getExitCode(),
                'error_output' => trim($process->getErrorOutput()),
                'output_preview' => mb_substr(trim($process->getOutput()), 0, 2000),
            ]);

            throw new RuntimeException('Page image extraction failed: '.trim($process->getErrorOutput().' '.$process->getOutput()));
        }

        $payload = json_decode($process->getOutput(), true);
        if (! is_array($payload) || ! isset($payload['page_images'])) {
            Log::warning('Unexpected page image extraction output', ['output' => $process->getOutput()]);
            throw new RuntimeException('Unexpected page image extraction output format.');
        }

        $payload['page_images'] = array_values(array_filter($payload['page_images'], static fn ($item) => is_string($item) && trim($item) !== ''));
        $payload['page_numbers'] = array_values(array_filter(array_map(static fn ($item) => is_numeric($item) ? (int) $item : null, $payload['page_numbers'] ?? []), static fn ($item) => is_int($item) && $item > 0));

        $this->trace($options, 'ocr.page_images.completed', [
            'page_numbers' => $payload['page_numbers'],
            'page_images_count' => count($payload['page_images']),
        ]);

        return $payload;
    }

    public function extractPageOcr(string $pdfPath, array $pageNumbers, array $options = []): array
    {
        $script = base_path('scripts/pdf_page_ocr.py');
        $timeoutSeconds = 180;
        $pageNumbers = array_values(array_unique(array_filter(array_map(static fn ($item) => is_numeric($item) ? (int) $item : null, $pageNumbers), static fn ($item) => is_int($item) && $item > 0)));

        if ($pageNumbers === []) {
            return [
                'ocr_pages' => [],
                'index_pages' => [],
                'page_numbers' => [],
                'index_text' => '',
            ];
        }

        if (! file_exists($script)) {
            throw new RuntimeException('Page OCR script not found at '.$script);
        }

        $command = [
            'python3',
            $script,
            $pdfPath,
            implode(',', $pageNumbers),
            config('services.ocr.languages', 'spa+eng'),
        ];

        $this->trace($options, 'ocr.page_ocr.start', [
            'script' => $script,
            'pdf_path' => $pdfPath,
            'page_numbers' => $pageNumbers,
            'command' => $command,
            'timeout_seconds' => $timeoutSeconds,
        ]);

        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->trace($options, 'ocr.page_ocr.failed', [
                'exit_code' => $process->getExitCode(),
                'error_output' => trim($process->getErrorOutput()),
                'output_preview' => mb_substr(trim($process->getOutput()), 0, 2000),
            ]);

            throw new RuntimeException('Page OCR extraction failed: '.trim($process->getErrorOutput().' '.$process->getOutput()));
        }

        $payload = json_decode($process->getOutput(), true);
        if (! is_array($payload) || ! isset($payload['ocr_pages']) || ! isset($payload['index_pages'])) {
            Log::warning('Unexpected page OCR extraction output', ['output' => $process->getOutput()]);
            throw new RuntimeException('Unexpected page OCR extraction output format.');
        }

        $payload['page_numbers'] = array_values(array_filter(array_map(static fn ($item) => is_numeric($item) ? (int) $item : null, $payload['page_numbers'] ?? []), static fn ($item) => is_int($item) && $item > 0));
        $payload['ocr_pages'] = $this->normalizeIndexedPagesPayload($payload['ocr_pages'] ?? []);
        $payload['index_pages'] = $this->normalizeIndexedPagesPayload($payload['index_pages'] ?? []);
        $payload['index_text'] = is_string($payload['index_text'] ?? null) ? trim($payload['index_text']) : '';

        $this->trace($options, 'ocr.page_ocr.completed', [
            'page_numbers' => $payload['page_numbers'],
            'ocr_pages_count' => count($payload['ocr_pages']),
            'index_pages_count' => count($payload['index_pages']),
        ]);

        return $payload;
    }

    private function normalizeIndexedPagesPayload(array $pages): array
    {
        $normalizedPages = [];

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $pageNumber = is_numeric($page['page_number'] ?? null) ? (int) $page['page_number'] : null;
            if (! $pageNumber || $pageNumber <= 0) {
                continue;
            }

            $normalizedPages[] = [
                'page_number' => $pageNumber,
                'text' => is_string($page['text'] ?? null) ? trim($page['text']) : '',
            ];
        }

        return $normalizedPages;
    }

    private function trace(array $options, string $step, array $data = []): void
    {
        $trace = $options['trace'] ?? null;

        if (is_callable($trace)) {
            $trace($step, $data);
        }
    }
}
