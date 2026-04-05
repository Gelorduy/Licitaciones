<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessUploadedPdfJob;
use App\Models\Regulation;
use App\Models\SystemEventLog;
use App\Services\SystemEventLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RegulacionController extends Controller
{
    public function index(Request $request): Response
    {
        $country = strtoupper((string) $request->query('country', ''));
        $scope = (string) $request->query('scope', '');

        $query = $request->user()
            ->regulations()
            ->with('documentIndex')
            ->latest();

        if ($country !== '') {
            $query->where('country_code', $country);
        }

        if ($scope !== '') {
            $query->where('scope', $scope);
        }

        return Inertia::render('Regulacion/Index', [
            'regulations' => $query
                ->get()
                ->map(function (Regulation $regulation) {
                    return [
                        ...$regulation->toArray(),
                        'source_pdf_name' => $regulation->source_pdf_original_name
                            ?: ($regulation->source_pdf_path ? basename($regulation->source_pdf_path) : null),
                    ];
                }),
            'filters' => [
                'country' => $country,
                'scope' => $scope,
            ],
            'availableScopes' => ['federal', 'estatal', 'local', 'entidad'],
            'availableCountries' => $request->user()->regulations()->select('country_code')->distinct()->orderBy('country_code')->pluck('country_code')->all(),
        ]);
    }

    public function viewFile(Request $request, Regulation $regulation): StreamedResponse
    {
        abort_unless($regulation->user_id === $request->user()->id, 403);

        SystemEventLogger::log('regulation.file_viewed', [
            'regulation_id' => $regulation->id,
            'file_name' => $regulation->source_pdf_original_name,
        ], $request, null, Regulation::class, $regulation->id);

        return $this->streamPdf($regulation, false);
    }

    public function downloadFile(Request $request, Regulation $regulation): StreamedResponse
    {
        abort_unless($regulation->user_id === $request->user()->id, 403);

        SystemEventLogger::log('regulation.file_downloaded', [
            'regulation_id' => $regulation->id,
            'file_name' => $regulation->source_pdf_original_name,
        ], $request, null, Regulation::class, $regulation->id);

        return $this->streamPdf($regulation, true);
    }

    public function viewExtractedText(Request $request, Regulation $regulation): Response
    {
        abort_unless($regulation->user_id === $request->user()->id, 403);

        $index = $regulation->documentIndex;

        SystemEventLogger::log('regulation.text_viewed', [
            'regulation_id' => $regulation->id,
        ], $request, null, Regulation::class, $regulation->id);

        return Inertia::render('Document/OcrText', [
            'documentLabel' => 'Regulación',
            'documentName' => $regulation->source_pdf_original_name ?: basename((string) $regulation->source_pdf_path),
            'status' => $index?->status,
            'extractionMethod' => $index?->extraction_method,
            'chunkCount' => $index?->chunk_count,
            'errorMessage' => $index?->error_message,
            'vectorIndexError' => $index?->vector_index_error,
            'extractedText' => $index?->extracted_text,
            'metadata' => $index?->metadata,
            'backUrl' => route('regulacion.edit', $regulation->id),
            'backLabel' => 'Volver a edición de regulación',
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Regulacion/Create', [
            'scopes' => ['federal', 'estatal', 'local', 'entidad'],
        ]);
    }

    public function edit(Request $request, Regulation $regulation): Response
    {
        abort_unless($regulation->user_id === $request->user()->id, 403);

        return Inertia::render('Regulacion/Edit', [
            'regulation' => $regulation,
            'fileHistory' => $this->fileHistory($regulation->id),
            'scopes' => ['federal', 'estatal', 'local', 'entidad'],
        ]);
    }

    private function fileHistory(int $regulationId): array
    {
        return SystemEventLog::query()
            ->with('user:id,name,email')
            ->where('entity_type', Regulation::class)
            ->where('entity_id', $regulationId)
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

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2'],
            'scope' => ['required', 'in:federal,estatal,local,entidad'],
            'regulatory_body' => ['nullable', 'string', 'max:255'],
            'general_description' => ['required', 'string'],
            'source_pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $pdfPath = null;

        try {
            $pdfPath = $request->file('source_pdf')->store(
                'module2/users/'.$request->user()->id.'/regulaciones',
                's3',
            );
        } catch (\Throwable $e) {
            Log::warning('Falling back to local storage for regulation upload', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $pdfPath) {
            $pdfPath = $request->file('source_pdf')->store(
                'module2/users/'.$request->user()->id.'/regulaciones',
                'public',
            );
        }

        $regulation = $request->user()->regulations()->create([
            'title' => $validated['title'],
            'country_code' => strtoupper($validated['country_code']),
            'scope' => $validated['scope'],
            'regulatory_body' => $validated['regulatory_body'] ?? null,
            'general_description' => $validated['general_description'],
            'source_pdf_path' => $pdfPath,
            'source_pdf_original_name' => $request->file('source_pdf')->getClientOriginalName(),
        ]);

        ProcessUploadedPdfJob::dispatch(Regulation::class, $regulation->id, 'regulation', $request->user()->id);
        SystemEventLogger::log('regulation.created', [
            'regulation_id' => $regulation->id,
            'file_name' => $regulation->source_pdf_original_name,
        ], $request, null, Regulation::class, $regulation->id);

        return redirect()
            ->route('regulacion.index')
            ->with('success', 'Regulación registrada. Procesando OCR e indexación vectorial.');
    }

    public function update(Request $request, Regulation $regulation): RedirectResponse
    {
        abort_unless($regulation->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2'],
            'scope' => ['required', 'in:federal,estatal,local,entidad'],
            'regulatory_body' => ['nullable', 'string', 'max:255'],
            'general_description' => ['required', 'string'],
            'source_pdf' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $payload = [
            'title' => $validated['title'],
            'country_code' => strtoupper($validated['country_code']),
            'scope' => $validated['scope'],
            'regulatory_body' => $validated['regulatory_body'] ?? null,
            'general_description' => $validated['general_description'],
        ];

        if ($request->hasFile('source_pdf')) {
            $pdfPath = null;

            try {
                $pdfPath = $request->file('source_pdf')->store(
                    'module2/users/'.$request->user()->id.'/regulaciones',
                    's3',
                );
            } catch (\Throwable $e) {
                Log::warning('Falling back to local storage for regulation upload update', [
                    'user_id' => $request->user()->id,
                    'regulation_id' => $regulation->id,
                    'error' => $e->getMessage(),
                ]);
            }

            if (! $pdfPath) {
                $pdfPath = $request->file('source_pdf')->store(
                    'module2/users/'.$request->user()->id.'/regulaciones',
                    'public',
                );
            }

            $payload['source_pdf_path'] = $pdfPath;
            $payload['source_pdf_original_name'] = $request->file('source_pdf')->getClientOriginalName();
        }

        $regulation->update($payload);

        if ($request->hasFile('source_pdf')) {
            ProcessUploadedPdfJob::dispatch(Regulation::class, $regulation->id, 'regulation', $request->user()->id);
            SystemEventLogger::log('regulation.file_replaced', [
                'regulation_id' => $regulation->id,
                'file_name' => $regulation->source_pdf_original_name,
            ], $request, null, Regulation::class, $regulation->id);
        } else {
            SystemEventLogger::log('regulation.updated', [
                'regulation_id' => $regulation->id,
            ], $request, null, Regulation::class, $regulation->id);
        }

        return redirect()
            ->route('regulacion.index')
            ->with('success', 'Regulación actualizada correctamente.');
    }

    private function streamPdf(Regulation $regulation, bool $download): StreamedResponse
    {
        abort_unless($regulation->source_pdf_path, 404);

        $disk = $this->resolveDiskForPath($regulation->source_pdf_path);
        $stream = Storage::disk($disk)->readStream($regulation->source_pdf_path);

        abort_unless(is_resource($stream), 404);

        $filename = $regulation->source_pdf_original_name ?: basename($regulation->source_pdf_path);

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
