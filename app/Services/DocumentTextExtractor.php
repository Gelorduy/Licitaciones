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

        $payload['vision_pages'] = $visionPages;
        $payload['vision_page_numbers'] = $visionPageNumbers;

        $this->trace($options, 'ocr.process.completed', [
            'extraction_method' => $payload['method'] ?? null,
            'chars' => $payload['chars'] ?? mb_strlen((string) ($payload['text'] ?? '')),
            'vision_pages_count' => count($visionPages),
            'vision_page_numbers' => $visionPageNumbers,
        ]);

        return $payload;
    }

    private function trace(array $options, string $step, array $data = []): void
    {
        $trace = $options['trace'] ?? null;

        if (is_callable($trace)) {
            $trace($step, $data);
        }
    }
}
