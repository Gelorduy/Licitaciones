<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeBasesJob;
use App\Models\Licitacion;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class LicitacionController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Licitacion/Index', [
            'licitaciones' => $request->user()
                ->licitaciones()
                ->with(['company:id,nombre,rfc', 'regulations:id,title', 'letterhead:id,title'])
                ->latest()
                ->get(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Licitacion/Create', [
            'companies' => $request->user()->companies()->orderBy('nombre')->get(['id', 'nombre', 'rfc']),
            'regulations' => $request->user()->regulations()->where('is_active', true)->orderBy('title')->get(['id', 'title', 'scope', 'country_code']),
            'letterheads' => $request->user()->letterheads()->orderBy('is_default', 'desc')->orderBy('title')->get(['id', 'company_id', 'title', 'is_default']),
            'processTypes' => ['publica', 'privada'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'company_id' => ['required', 'integer'],
            'company_letterhead_id' => ['nullable', 'integer'],
            'process_type' => ['required', 'in:publica,privada'],
            'legal_signer_name' => ['nullable', 'string', 'max:255'],
            'regulation_ids' => ['nullable', 'array'],
            'regulation_ids.*' => ['integer'],
            'bases_document' => ['nullable', 'file', 'mimes:pdf', 'max:30720'],
        ]);

        $company = $request->user()->companies()->findOrFail($validated['company_id']);

        $payload = [
            'user_id' => $request->user()->id,
            'company_id' => $company->id,
            'company_letterhead_id' => null,
            'title' => $validated['title'],
            'process_type' => $validated['process_type'],
            'legal_signer_name' => $validated['legal_signer_name'] ?? null,
            'status' => 'draft',
            'checklist' => [
                ['label' => 'Empresa seleccionada', 'checked' => true],
                ['label' => 'Regulaciones asignadas', 'checked' => ! empty($validated['regulation_ids'])],
                ['label' => 'Bases cargadas', 'checked' => $request->hasFile('bases_document')],
            ],
        ];

        if (! empty($validated['company_letterhead_id'])) {
            $letterheadId = $company->letterheads()->where('id', $validated['company_letterhead_id'])->value('id');
            $payload['company_letterhead_id'] = $letterheadId;
        }

        if ($request->hasFile('bases_document')) {
            $path = null;
            try {
                $path = $request->file('bases_document')->store('module3/users/'.$request->user()->id.'/licitaciones/bases', 's3');
            } catch (\Throwable $e) {
                Log::warning('Falling back to local storage for licitacion bases upload', ['error' => $e->getMessage()]);
            }

            $payload['bases_document_path'] = $path ?: $request->file('bases_document')->store('module3/users/'.$request->user()->id.'/licitaciones/bases', 'public');
            $payload['bases_document_original_name'] = $request->file('bases_document')->getClientOriginalName();
        }

        $licitacion = Licitacion::create($payload);

        if (! empty($validated['regulation_ids'])) {
            $regulationIds = $request->user()->regulations()->whereIn('id', $validated['regulation_ids'])->pluck('id')->all();
            $licitacion->regulations()->sync($regulationIds);
        }

        if ($request->hasFile('bases_document')) {
            AnalyzeBasesJob::dispatch($licitacion->id);
        }

        return redirect()->route('licitacion.show', $licitacion->id)->with('success', 'Expediente de licitación creado.');
    }

    public function show(Request $request, Licitacion $licitacion): Response
    {
        abort_unless($licitacion->user_id === $request->user()->id, 403);

        $licitacion->load(['company:id,nombre,rfc', 'regulations:id,title,scope,country_code', 'letterhead:id,title,city,contact_name,contact_position,phone,email,body_template']);

        return Inertia::render('Licitacion/Show', [
            'licitacion' => $licitacion,
        ]);
    }
}
