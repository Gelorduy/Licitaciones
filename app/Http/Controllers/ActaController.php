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
            ...collect($validated)->except('documento')->all(),
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

        return Inertia::render('Acta/Edit', [
            'company' => [
                'id' => $company->id,
                'nombre' => $company->nombre,
                'rfc' => $company->rfc,
            ],
            'acta' => $acta,
            'fileHistory' => $this->fileHistory($acta->id),
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
        ]);

        $payload = collect($validated)->except('documento')->all();
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
}
