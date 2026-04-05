<?php

namespace App\Jobs;

use App\Models\Licitacion;
use App\Services\DocumentTextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AnalyzeBasesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $licitacionId)
    {
    }

    public function handle(DocumentTextExtractor $textExtractor): void
    {
        $licitacion = Licitacion::query()->find($this->licitacionId);

        if (! $licitacion || ! $licitacion->bases_document_path) {
            return;
        }

        $licitacion->update(['status' => 'analyzing']);

        try {
            $path = $licitacion->bases_document_path;
            $disk = Storage::disk('s3')->exists($path) ? 's3' : 'public';

            $binary = Storage::disk($disk)->get($path);
            $tmp = tempnam(sys_get_temp_dir(), 'bases_');
            file_put_contents($tmp, $binary);

            $extracted = $textExtractor->extract($tmp);
            @unlink($tmp);

            $text = trim((string) ($extracted['text'] ?? ''));

            $checklist = $this->buildChecklist($text);

            $licitacion->update([
                'status' => 'ready',
                'checklist' => $checklist,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AnalyzeBasesJob failed', [
                'licitacion_id' => $licitacion->id,
                'error' => $e->getMessage(),
            ]);

            $licitacion->update([
                'status' => 'draft',
                'checklist' => [
                    ['label' => 'Error al analizar bases, requiere revisión manual', 'checked' => false],
                ],
            ]);
        }
    }

    private function buildChecklist(string $text): array
    {
        $lower = mb_strtolower($text);

        return [
            ['label' => 'Se detectó cronograma/fechas relevantes', 'checked' => $this->containsAny($lower, ['fecha', 'cronograma', 'calendario', 'plazo'])],
            ['label' => 'Se detectaron requisitos técnicos', 'checked' => $this->containsAny($lower, ['especificación', 'técnic', 'características'])],
            ['label' => 'Se detectaron requisitos económicos', 'checked' => $this->containsAny($lower, ['precio', 'propuesta económica', 'cotización'])],
            ['label' => 'Se detectaron requisitos administrativos', 'checked' => $this->containsAny($lower, ['anexo', 'documentación', 'constancia'])],
            ['label' => 'Se detectaron criterios de evaluación', 'checked' => $this->containsAny($lower, ['criterio', 'evaluación', 'puntaje'])],
        ];
    }

    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }

        return false;
    }
}
