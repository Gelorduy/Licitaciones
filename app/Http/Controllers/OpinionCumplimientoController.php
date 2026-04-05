<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessUploadedPdfJob;
use App\Models\Company;
use App\Models\OpinionCumplimiento;
use App\Models\SystemEventLog;
use App\Services\SystemEventLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OpinionCumplimientoController extends Controller
{
    public function create(Request $request, Company $company): Response
    {
        abort_unless($company->user_id === $request->user()->id, 403);

        return Inertia::render('Opinion/Create', [
            'company' => [
                'id' => $company->id,
                'nombre' => $company->nombre,
                'rfc' => $company->rfc,
            ],
            'tipos' => ['sat', 'infonavit', 'imss'],
            'estados' => ['positivo', 'negativo', 'pendiente'],
        ]);
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        abort_unless($company->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'tipo' => ['required', 'in:sat,infonavit,imss'],
            'estado' => ['required', 'in:positivo,negativo,pendiente'],
            'fecha_emision' => ['required', 'date'],
            'fecha_vigencia' => ['nullable', 'date', 'after_or_equal:fecha_emision'],
            'documento' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $documentPath = null;

        try {
            $documentPath = $request->file('documento')->store(
                'module1/companies/'.$company->id.'/opiniones',
                's3',
            );
        } catch (\Throwable $e) {
            Log::warning('Falling back to local storage for opinion upload', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $documentPath) {
            $documentPath = $request->file('documento')->store(
                'module1/companies/'.$company->id.'/opiniones',
                'public',
            );
        }

        $opinion = $company->opinionesCumplimiento()->create([
            ...collect($validated)->except('documento')->all(),
            'documento_path' => $documentPath,
            'documento_original_name' => $request->file('documento')->getClientOriginalName(),
        ]);

        ProcessUploadedPdfJob::dispatch(OpinionCumplimiento::class, $opinion->id, 'opinion', $request->user()->id);
        SystemEventLogger::log('opinion.created', [
            'company_id' => $company->id,
            'opinion_id' => $opinion->id,
            'file_name' => $opinion->documento_original_name,
        ], $request, null, OpinionCumplimiento::class, $opinion->id);

        return redirect()
            ->route('empresa.show', $company)
            ->with('success', 'Opinión registrada. Procesando OCR e indexación vectorial.');
    }

    public function edit(Request $request, Company $company, OpinionCumplimiento $opinion): Response
    {
        abort_unless($company->user_id === $request->user()->id && $opinion->company_id === $company->id, 403);

        return Inertia::render('Opinion/Edit', [
            'company' => [
                'id' => $company->id,
                'nombre' => $company->nombre,
                'rfc' => $company->rfc,
            ],
            'opinion' => $opinion,
            'fileHistory' => $this->fileHistory($opinion->id),
            'tipos' => ['sat', 'infonavit', 'imss'],
            'estados' => ['positivo', 'negativo', 'pendiente'],
        ]);
    }

    public function update(Request $request, Company $company, OpinionCumplimiento $opinion): RedirectResponse
    {
        abort_unless($company->user_id === $request->user()->id && $opinion->company_id === $company->id, 403);

        $validated = $request->validate([
            'tipo' => ['required', 'in:sat,infonavit,imss'],
            'estado' => ['required', 'in:positivo,negativo,pendiente'],
            'fecha_emision' => ['required', 'date'],
            'fecha_vigencia' => ['nullable', 'date', 'after_or_equal:fecha_emision'],
            'documento' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $payload = collect($validated)->except('documento')->all();
        $fileWasReplaced = false;

        if ($request->hasFile('documento')) {
            $documentPath = null;

            try {
                $documentPath = $request->file('documento')->store(
                    'module1/companies/'.$company->id.'/opiniones',
                    's3',
                );
            } catch (\Throwable $e) {
                Log::warning('Falling back to local storage for opinion upload update', [
                    'company_id' => $company->id,
                    'opinion_id' => $opinion->id,
                    'error' => $e->getMessage(),
                ]);
            }

            if (! $documentPath) {
                $documentPath = $request->file('documento')->store(
                    'module1/companies/'.$company->id.'/opiniones',
                    'public',
                );
            }

            $payload['documento_path'] = $documentPath;
            $payload['documento_original_name'] = $request->file('documento')->getClientOriginalName();
            $fileWasReplaced = true;
        }

        $opinion->update($payload);

        if ($fileWasReplaced) {
            ProcessUploadedPdfJob::dispatch(OpinionCumplimiento::class, $opinion->id, 'opinion', $request->user()->id);
            SystemEventLogger::log('opinion.file_replaced', [
                'company_id' => $company->id,
                'opinion_id' => $opinion->id,
                'file_name' => $opinion->documento_original_name,
            ], $request, null, OpinionCumplimiento::class, $opinion->id);
        } else {
            SystemEventLogger::log('opinion.updated', [
                'company_id' => $company->id,
                'opinion_id' => $opinion->id,
            ], $request, null, OpinionCumplimiento::class, $opinion->id);
        }

        return redirect()
            ->route('empresa.show', $company)
            ->with('success', 'Opinión actualizada correctamente.');
    }

    public function viewFile(Request $request, Company $company, OpinionCumplimiento $opinion): StreamedResponse
    {
        abort_unless($company->user_id === $request->user()->id && $opinion->company_id === $company->id, 403);

        SystemEventLogger::log('opinion.file_viewed', [
            'company_id' => $company->id,
            'opinion_id' => $opinion->id,
            'file_name' => $opinion->documento_original_name,
        ], $request, null, OpinionCumplimiento::class, $opinion->id);

        return $this->streamPdf($opinion, false);
    }

    public function downloadFile(Request $request, Company $company, OpinionCumplimiento $opinion): StreamedResponse
    {
        abort_unless($company->user_id === $request->user()->id && $opinion->company_id === $company->id, 403);

        SystemEventLogger::log('opinion.file_downloaded', [
            'company_id' => $company->id,
            'opinion_id' => $opinion->id,
            'file_name' => $opinion->documento_original_name,
        ], $request, null, OpinionCumplimiento::class, $opinion->id);

        return $this->streamPdf($opinion, true);
    }

    public function viewExtractedText(Request $request, Company $company, OpinionCumplimiento $opinion): Response
    {
        abort_unless($company->user_id === $request->user()->id && $opinion->company_id === $company->id, 403);

        $index = $opinion->documentIndex;

        SystemEventLogger::log('opinion.text_viewed', [
            'company_id' => $company->id,
            'opinion_id' => $opinion->id,
        ], $request, null, OpinionCumplimiento::class, $opinion->id);

        return Inertia::render('Document/OcrText', [
            'documentLabel' => 'Opinión de Cumplimiento',
            'documentName' => $opinion->documento_original_name ?: basename((string) $opinion->documento_path),
            'status' => $index?->status,
            'extractionMethod' => $index?->extraction_method,
            'chunkCount' => $index?->chunk_count,
            'errorMessage' => $index?->error_message,
            'vectorIndexError' => $index?->vector_index_error,
            'extractedText' => $index?->extracted_text,
            'metadata' => $index?->metadata,
            'backUrl' => route('opinion.edit', [$company->id, $opinion->id]),
            'backLabel' => 'Volver a edición de opinión',
        ]);
    }

    private function fileHistory(int $opinionId): array
    {
        return SystemEventLog::query()
            ->with('user:id,name,email')
            ->where('entity_type', OpinionCumplimiento::class)
            ->where('entity_id', $opinionId)
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

    private function streamPdf(OpinionCumplimiento $opinion, bool $download): StreamedResponse
    {
        abort_unless($opinion->documento_path, 404);

        $disk = $this->resolveDiskForPath($opinion->documento_path);
        $stream = Storage::disk($disk)->readStream($opinion->documento_path);

        abort_unless(is_resource($stream), 404);

        $filename = $opinion->documento_original_name ?: basename($opinion->documento_path);

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
