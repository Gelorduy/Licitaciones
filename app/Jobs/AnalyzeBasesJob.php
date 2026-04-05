<?php

namespace App\Jobs;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\Licitacion;
use App\Services\DocumentTextExtractor;
use App\Services\WorkflowStateMachine;
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

    public function handle(DocumentTextExtractor $textExtractor, WorkflowStateMachine $workflow): void
    {
        $licitacion = Licitacion::query()->find($this->licitacionId);

        if (! $licitacion || ! $licitacion->bases_document_path) {
            return;
        }

        try {
            $workflow->transition(
                model: $licitacion,
                toState: 'analyzing',
                triggeredByUserId: $licitacion->user_id,
                reason: 'Inicio de análisis asíncrono de bases',
                metadata: ['job' => self::class],
            );
        } catch (InvalidStateTransitionException $e) {
            Log::warning('AnalyzeBasesJob blocked by workflow transition rule', [
                'licitacion_id' => $licitacion->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

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
                'checklist' => $checklist,
            ]);

            $workflow->transition(
                model: $licitacion,
                toState: 'ready',
                triggeredByUserId: $licitacion->user_id,
                reason: 'Análisis de bases completado',
                metadata: ['checklist_items' => count($checklist)],
            );
        } catch (\Throwable $e) {
            Log::warning('AnalyzeBasesJob failed', [
                'licitacion_id' => $licitacion->id,
                'error' => $e->getMessage(),
            ]);

            $licitacion->update([
                'checklist' => [
                    ['label' => 'Error al analizar bases, requiere revisión manual', 'checked' => false],
                ],
            ]);

            try {
                $workflow->transition(
                    model: $licitacion,
                    toState: 'draft',
                    triggeredByUserId: $licitacion->user_id,
                    reason: 'Fallo en análisis de bases',
                    metadata: ['error' => $e->getMessage()],
                );
            } catch (InvalidStateTransitionException $transitionError) {
                Log::warning('AnalyzeBasesJob could not rollback status by workflow rule', [
                    'licitacion_id' => $licitacion->id,
                    'error' => $transitionError->getMessage(),
                ]);
            }
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
