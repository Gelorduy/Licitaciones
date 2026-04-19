<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessUploadedPdfJob;
use App\Models\Acta;
use App\Models\Company;
use App\Models\SystemEventLog;
use App\Services\SystemEventLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActaController extends Controller
{
    public function create(Request $request, Company $company): Response
    {
        abort_unless($company->user_id === $request->user()->id, 403);

        return Inertia::render('Acta/Create', [
            'company' => [
                'id' => $company->id,
                'nombre' => $company->nombre,
                'rfc' => $company->rfc,
            ],
            'tipos' => ['constitutiva', 'modificacion', 'poderes'],
        ]);
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        abort_unless($company->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'tipo' => ['required', 'in:constitutiva,modificacion,poderes'],
            'fecha_registro' => ['nullable', 'date'],
            'rpc_fecha_inscripcion' => ['nullable', 'date'],
            'fecha_inscripcion' => ['nullable', 'date'],
            'documento' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'rpc_folio' => ['nullable', 'string', 'max:255'],
            'rpc_lugar' => ['nullable', 'string', 'max:255'],
            'notaria_numero' => ['nullable', 'string', 'max:255'],
            'notaria_lugar' => ['nullable', 'string', 'max:255'],
            'notario_nombre' => ['nullable', 'string', 'max:255'],
            'escritura_numero' => ['nullable', 'string', 'max:255'],
            'libro_numero' => ['nullable', 'string', 'max:255'],
            'acto' => ['nullable', 'string', 'max:255'],
            'apoderados' => ['nullable', 'array'],
            'apoderados.*.nombre_completo' => ['nullable', 'string', 'max:255'],
            'apoderados.*.ine' => ['nullable', 'string', 'max:255'],
            'apoderados.*.poder_documento' => ['nullable', 'string', 'max:500'],
            'apoderados.*.facultades_otorgadas' => ['nullable', 'string', 'max:2000'],
            'participacion_accionaria' => ['nullable', 'array'],
            'participacion_accionaria.*.socio' => ['nullable', 'string', 'max:255'],
            'participacion_accionaria.*.porcentaje' => ['nullable', 'string', 'max:255'],
            'consejo_administracion' => ['nullable', 'array'],
            'consejo_administracion.*' => ['nullable', 'string', 'max:255'],
            'direccion_empresa' => ['nullable', 'array'],
            'direccion_empresa.*' => ['nullable', 'string', 'max:500'],
        ]);

        $documentPath = null;

        try {
            $documentPath = $request->file('documento')->store(
                'module1/companies/'.$company->id.'/actas',
                's3',
            );
        } catch (\Throwable $e) {
            Log::warning('Falling back to local storage for acta upload', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $documentPath) {
            $documentPath = $request->file('documento')->store(
                'module1/companies/'.$company->id.'/actas',
                'public',
            );
        }

        $acta = $company->actas()->create([
            ...$this->normalizeActaPayload($validated),
            'documento_path' => $documentPath,
            'documento_original_name' => $request->file('documento')->getClientOriginalName(),
        ]);

        ProcessUploadedPdfJob::dispatch(Acta::class, $acta->id, 'acta', $request->user()->id);
        SystemEventLogger::log('acta.created', [
            'company_id' => $company->id,
            'acta_id' => $acta->id,
            'file_name' => $acta->documento_original_name,
        ], $request, null, Acta::class, $acta->id);

        return redirect()
            ->route('empresa.show', $company)
            ->with('success', 'Acta registrada. Procesando OCR e indexación vectorial.');
    }

    public function edit(Request $request, Company $company, Acta $acta): Response
    {
        abort_unless($company->user_id === $request->user()->id && $acta->company_id === $company->id, 403);

        $acta->load('documentIndex');
        $processingTrace = $this->loadProcessingTrace($acta);

        return Inertia::render('Acta/Edit', [
            'company' => [
                'id' => $company->id,
                'nombre' => $company->nombre,
                'rfc' => $company->rfc,
            ],
            'acta' => $acta,
            'fileHistory' => $this->fileHistory($acta->id),
            'processingTrace' => $processingTrace,
            'processingTraceDownloadUrl' => route('acta.trace.download', [$company->id, $acta->id]),
            'tipos' => ['constitutiva', 'modificacion', 'poderes'],
        ]);
    }

    public function update(Request $request, Company $company, Acta $acta): RedirectResponse
    {
        abort_unless($company->user_id === $request->user()->id && $acta->company_id === $company->id, 403);

        $validated = $request->validate([
            'tipo' => ['required', 'in:constitutiva,modificacion,poderes'],
            'fecha_registro' => ['nullable', 'date'],
            'rpc_fecha_inscripcion' => ['nullable', 'date'],
            'fecha_inscripcion' => ['nullable', 'date'],
            'documento' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'rpc_folio' => ['nullable', 'string', 'max:255'],
            'rpc_lugar' => ['nullable', 'string', 'max:255'],
            'notaria_numero' => ['nullable', 'string', 'max:255'],
            'notaria_lugar' => ['nullable', 'string', 'max:255'],
            'notario_nombre' => ['nullable', 'string', 'max:255'],
            'escritura_numero' => ['nullable', 'string', 'max:255'],
            'libro_numero' => ['nullable', 'string', 'max:255'],
            'acto' => ['nullable', 'string', 'max:255'],
            'apoderados' => ['nullable', 'array'],
            'apoderados.*.nombre_completo' => ['nullable', 'string', 'max:255'],
            'apoderados.*.ine' => ['nullable', 'string', 'max:255'],
            'apoderados.*.poder_documento' => ['nullable', 'string', 'max:500'],
            'apoderados.*.facultades_otorgadas' => ['nullable', 'string', 'max:2000'],
            'participacion_accionaria' => ['nullable', 'array'],
            'participacion_accionaria.*.socio' => ['nullable', 'string', 'max:255'],
            'participacion_accionaria.*.porcentaje' => ['nullable', 'string', 'max:255'],
            'consejo_administracion' => ['nullable', 'array'],
            'consejo_administracion.*' => ['nullable', 'string', 'max:255'],
            'direccion_empresa' => ['nullable', 'array'],
            'direccion_empresa.*' => ['nullable', 'string', 'max:500'],
        ]);

        $payload = $this->normalizeActaPayload($validated);
        $fileWasReplaced = false;

        if ($request->hasFile('documento')) {
            $documentPath = null;

            try {
                $documentPath = $request->file('documento')->store(
                    'module1/companies/'.$company->id.'/actas',
                    's3',
                );
            } catch (\Throwable $e) {
                Log::warning('Falling back to local storage for acta upload update', [
                    'company_id' => $company->id,
                    'acta_id' => $acta->id,
                    'error' => $e->getMessage(),
                ]);
            }

            if (! $documentPath) {
                $documentPath = $request->file('documento')->store(
                    'module1/companies/'.$company->id.'/actas',
                    'public',
                );
            }

            $payload['documento_path'] = $documentPath;
            $payload['documento_original_name'] = $request->file('documento')->getClientOriginalName();
            $fileWasReplaced = true;
        }

        $acta->update($payload);

        if ($fileWasReplaced) {
            ProcessUploadedPdfJob::dispatch(Acta::class, $acta->id, 'acta', $request->user()->id);
            SystemEventLogger::log('acta.file_replaced', [
                'company_id' => $company->id,
                'acta_id' => $acta->id,
                'file_name' => $acta->documento_original_name,
            ], $request, null, Acta::class, $acta->id);
        } else {
            SystemEventLogger::log('acta.updated', [
                'company_id' => $company->id,
                'acta_id' => $acta->id,
            ], $request, null, Acta::class, $acta->id);
        }

        return redirect()
            ->route('empresa.show', $company)
            ->with('success', 'Acta actualizada correctamente.');
    }

    public function reextract(Request $request, Company $company, Acta $acta): RedirectResponse
    {
        abort_unless($company->user_id === $request->user()->id && $acta->company_id === $company->id, 403);

        abort_if(! $acta->documento_path, 422, 'El acta no tiene archivo para reextraer.');

        ProcessUploadedPdfJob::dispatch(Acta::class, $acta->id, 'acta', $request->user()->id);

        SystemEventLogger::log('acta.reextract_requested', [
            'company_id' => $company->id,
            'acta_id' => $acta->id,
            'file_name' => $acta->documento_original_name,
        ], $request, null, Acta::class, $acta->id);

        return back()->with('success', 'Reextracción de campos solicitada. Procesando en segundo plano.');
    }

    public function viewFile(Request $request, Company $company, Acta $acta): StreamedResponse
    {
        abort_unless($company->user_id === $request->user()->id && $acta->company_id === $company->id, 403);

        SystemEventLogger::log('acta.file_viewed', [
            'company_id' => $company->id,
            'acta_id' => $acta->id,
            'file_name' => $acta->documento_original_name,
        ], $request, null, Acta::class, $acta->id);

        return $this->streamPdf($acta, false);
    }

    public function downloadFile(Request $request, Company $company, Acta $acta): StreamedResponse
    {
        abort_unless($company->user_id === $request->user()->id && $acta->company_id === $company->id, 403);

        SystemEventLogger::log('acta.file_downloaded', [
            'company_id' => $company->id,
            'acta_id' => $acta->id,
            'file_name' => $acta->documento_original_name,
        ], $request, null, Acta::class, $acta->id);

        return $this->streamPdf($acta, true);
    }

    public function viewExtractedText(Request $request, Company $company, Acta $acta): Response
    {
        abort_unless($company->user_id === $request->user()->id && $acta->company_id === $company->id, 403);

        $index = $acta->documentIndex;

        SystemEventLogger::log('acta.text_viewed', [
            'company_id' => $company->id,
            'acta_id' => $acta->id,
        ], $request, null, Acta::class, $acta->id);

        return Inertia::render('Document/OcrText', [
            'documentLabel' => 'Acta',
            'documentName' => $acta->documento_original_name ?: basename((string) $acta->documento_path),
            'status' => $index?->status,
            'extractionMethod' => $index?->extraction_method,
            'chunkCount' => $index?->chunk_count,
            'errorMessage' => $index?->error_message,
            'vectorIndexError' => $index?->vector_index_error,
            'extractedText' => $index?->extracted_text,
            'metadata' => $index?->metadata,
            'backUrl' => route('acta.edit', [$company->id, $acta->id]),
            'backLabel' => 'Volver a edición de acta',
        ]);
    }

    public function downloadTrace(Request $request, Company $company, Acta $acta): StreamedResponse
    {
        abort_unless($company->user_id === $request->user()->id && $acta->company_id === $company->id, 403);

        $trace = $this->loadProcessingTrace($acta);
        abort_unless(is_array($trace), 404, 'No hay traza de procesamiento disponible para esta acta.');

        $filename = sprintf('acta-%d-processing-trace.json', $acta->id);

        return response()->streamDownload(function () use ($trace): void {
            echo json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $filename, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    private function fileHistory(int $actaId): array
    {
        return SystemEventLog::query()
            ->with('user:id,name,email')
            ->where('entity_type', Acta::class)
            ->where('entity_id', $actaId)
            ->latest('id')
            ->limit(12)
            ->get()
            ->map(fn (SystemEventLog $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'created_at' => $event->created_at?->toDateTimeString(),
                'user_name' => $event->user?->name,
                'metadata' => $event->metadata,
            ])
            ->all();
    }

    private function loadProcessingTrace(Acta $acta): ?array
    {
        $tracePath = data_get($acta->documentIndex?->metadata, 'processing_trace.latest_path');

        if (! is_string($tracePath) || $tracePath === '') {
            return null;
        }

        if (! Storage::disk('local')->exists($tracePath)) {
            return null;
        }

        $payload = Storage::disk('local')->get($tracePath);
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function streamPdf(Acta $acta, bool $download): StreamedResponse
    {
        abort_unless($acta->documento_path, 404);

        $disk = $this->resolveDiskForPath($acta->documento_path);
        $stream = Storage::disk($disk)->readStream($acta->documento_path);

        abort_unless(is_resource($stream), 404);

        $filename = $acta->documento_original_name ?: basename($acta->documento_path);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('%s; filename="%s"', $download ? 'attachment' : 'inline', $filename),
        ]);
    }

    private function resolveDiskForPath(string $path): string
    {
        try {
            if (Storage::disk('s3')->exists($path)) {
                return 's3';
            }
        } catch (\Throwable) {
            // Ignore and continue fallback checks.
        }

        try {
            if (Storage::disk('public')->exists($path)) {
                return 'public';
            }
        } catch (\Throwable) {
            // Ignore and use default.
        }

        return 's3';
    }

    private function normalizeActaPayload(array $validated): array
    {
        return [
            ...collect($validated)->except([
                'documento',
                'apoderados',
                'participacion_accionaria',
                'consejo_administracion',
                'direccion_empresa',
            ])->all(),
            'apoderados' => $this->normalizeStructuredEntries($validated['apoderados'] ?? [], [
                'nombre_completo',
                'ine',
                'poder_documento',
                'facultades_otorgadas',
            ]),
            'participacion_accionaria' => $this->normalizeStructuredEntries($validated['participacion_accionaria'] ?? [], [
                'socio',
                'porcentaje',
            ]),
            'consejo_administracion' => $this->normalizeStringArray($validated['consejo_administracion'] ?? []),
            'direccion_empresa' => $this->normalizeStringArray($validated['direccion_empresa'] ?? []),
        ];
    }

    private function normalizeStructuredEntries(array $items, array $fields): ?array
    {
        $normalized = collect($items)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) use ($fields): array {
                $row = [];

                foreach ($fields as $field) {
                    $row[$field] = $this->normalizeNullableString($item[$field] ?? null);
                }

                return $row;
            })
            ->filter(function (array $row): bool {
                foreach ($row as $value) {
                    if ($value !== null) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->all();

        return $normalized === [] ? null : $normalized;
    }

    private function normalizeStringArray(array $items): ?array
    {
        $normalized = collect($items)
            ->map(fn ($item) => $this->normalizeNullableString($item))
            ->filter(fn ($item) => $item !== null)
            ->values()
            ->all();

        return $normalized === [] ? null : $normalized;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
