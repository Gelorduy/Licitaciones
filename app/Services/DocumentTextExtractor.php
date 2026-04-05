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

        return $payload;
    }
}
