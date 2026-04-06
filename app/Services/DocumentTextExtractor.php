<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class DocumentTextExtractor
{
    public function extract(string $pdfPath): array
    {
        $script = base_path('scripts/pdf_extract.py');

        if (! file_exists($script)) {
            throw new RuntimeException('OCR script not found at '.$script);
        }

        $process = new Process([
            'python3',
            $script,
            $pdfPath,
            config('services.ocr.languages', 'spa+eng'),
            (string) max((int) config('services.ocr.vision_pages', 3), 0),
            (string) max((int) config('services.ocr.vision_max_width', 1400), 800),
            (string) max((int) config('services.ocr.vision_quality', 70), 40),
        ]);

        $process->setTimeout(240);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('PDF extraction failed: '.trim($process->getErrorOutput().' '.$process->getOutput()));
        }

        $payload = json_decode($process->getOutput(), true);

        if (! is_array($payload) || ! isset($payload['text'])) {
            Log::warning('Unexpected OCR output', ['output' => $process->getOutput()]);
            throw new RuntimeException('Unexpected OCR output format.');
        }

        $visionPages = [];
        if (isset($payload['vision_pages']) && is_array($payload['vision_pages'])) {
            $visionPages = array_values(array_filter($payload['vision_pages'], static fn ($item) => is_string($item) && trim($item) !== ''));
        }

        $payload['vision_pages'] = $visionPages;

        return $payload;
    }
}
