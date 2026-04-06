<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidStateTransitionException;
use App\Jobs\AnalyzeBasesJob;
use App\Models\Licitacion;
use App\Services\WorkflowStateMachine;
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
            'letterheads' => $request->user()->letterheads()->orderBy('is_default', 'desc')->orderBy('title')->get([
                'company_letterheads.id',
                'company_letterheads.company_id',
                'company_letterheads.title',
                'company_letterheads.is_default',
            ]),
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

        $licitacion->load([
            'company:id,nombre,rfc',
            'regulations:id,title,scope,country_code',
            'letterhead:id,title,city,contact_name,contact_position,phone,email,body_template',
            'validation:id,licitacion_id,status,override_applied',
        ]);

        return Inertia::render('Licitacion/Show', [
            'licitacion' => $licitacion,
        ]);
    }

    public function edit(Request $request, Licitacion $licitacion): Response
    {
        abort_unless($licitacion->user_id === $request->user()->id, 403);
        abort_if($licitacion->isCommitted(), 422, 'La licitacion ya fue comprometida y no puede editarse.');

        $licitacion->load(['regulations:id']);

        return Inertia::render('Licitacion/Edit', [
            'licitacion' => [
                'id' => $licitacion->id,
                'title' => $licitacion->title,
                'company_id' => $licitacion->company_id,
                'company_letterhead_id' => $licitacion->company_letterhead_id,
                'process_type' => $licitacion->process_type,
                'legal_signer_name' => $licitacion->legal_signer_name,
                'regulation_ids' => $licitacion->regulations->pluck('id')->all(),
                'bases_document_original_name' => $licitacion->bases_document_original_name,
            ],
            'companies' => $request->user()->companies()->orderBy('nombre')->get(['id', 'nombre', 'rfc']),
            'regulations' => $request->user()->regulations()->where('is_active', true)->orderBy('title')->get(['id', 'title', 'scope', 'country_code']),
            'letterheads' => $request->user()->letterheads()->orderBy('is_default', 'desc')->orderBy('title')->get([
                'company_letterheads.id',
                'company_letterheads.company_id',
                'company_letterheads.title',
                'company_letterheads.is_default',
            ]),
            'processTypes' => ['publica', 'privada'],
        ]);
    }

    public function update(Request $request, Licitacion $licitacion): RedirectResponse
    {
        abort_unless($licitacion->user_id === $request->user()->id, 403);
        abort_if($licitacion->isCommitted(), 422, 'La licitacion ya fue comprometida y no puede editarse.');

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
            'company_id' => $company->id,
            'company_letterhead_id' => null,
            'title' => $validated['title'],
            'process_type' => $validated['process_type'],
            'legal_signer_name' => $validated['legal_signer_name'] ?? null,
        ];

        if (! empty($validated['company_letterhead_id'])) {
            $payload['company_letterhead_id'] = $company->letterheads()
                ->where('id', $validated['company_letterhead_id'])
                ->value('id');
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

        $regulationIds = [];
        if (! empty($validated['regulation_ids'])) {
            $regulationIds = $request->user()->regulations()->whereIn('id', $validated['regulation_ids'])->pluck('id')->all();
        }

        $payload['checklist'] = [
            ['label' => 'Empresa seleccionada', 'checked' => true],
            ['label' => 'Regulaciones asignadas', 'checked' => ! empty($regulationIds)],
            ['label' => 'Bases cargadas', 'checked' => ! empty($payload['bases_document_path'] ?? $licitacion->bases_document_path)],
        ];

        $licitacion->update($payload);
        $licitacion->regulations()->sync($regulationIds);

        if ($request->hasFile('bases_document')) {
            AnalyzeBasesJob::dispatch($licitacion->id);
        }

        return redirect()->route('licitacion.show', $licitacion->id)->with('success', 'Expediente actualizado.');
    }

    public function sendForApproval(Request $request, Licitacion $licitacion, WorkflowStateMachine $workflow): RedirectResponse
    {
        abort_unless($licitacion->user_id === $request->user()->id, 403);
        abort_if($licitacion->isCommitted(), 422, 'La licitacion ya fue comprometida y no puede reenviarse.');

        $validation = $licitacion->validation;
        abort_if(
            ! $validation || ($validation->status !== 'ready_for_export' && ! $validation->override_applied),
            422,
            'La licitacion debe estar lista para exportacion antes de enviarse a aprobacion humana.'
        );

        try {
            $workflow->transition(
                model: $licitacion,
                toState: 'sent_for_approval',
                triggeredByUserId: $request->user()->id,
                reason: 'Envio a aprobacion humana previo a compromiso',
                metadata: [
                    'validation_id' => $validation->id,
                    'validation_status' => $validation->status,
                ],
            );
        } catch (InvalidStateTransitionException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', 'Expediente enviado a aprobacion humana.');
    }

    public function approveSubmission(Request $request, Licitacion $licitacion, WorkflowStateMachine $workflow): RedirectResponse
    {
        abort_unless($licitacion->user_id === $request->user()->id, 403);
        abort_if($licitacion->isCommitted(), 422, 'La licitacion ya fue comprometida.');

        $validated = $request->validate([
            'approval_note' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        try {
            $workflow->transition(
                model: $licitacion,
                toState: 'committed',
                triggeredByUserId: $request->user()->id,
                reason: 'Aprobacion humana de envio al gobierno',
                metadata: [
                    'approval_note' => $validated['approval_note'],
                    'approved_by_email' => $request->user()->email,
                ],
            );
        } catch (InvalidStateTransitionException $e) {
            return back()->withErrors(['approval_note' => $e->getMessage()]);
        }

        return back()->with('success', 'Licitacion aprobada y comprometida. Ya es inmutable.');
    }
}
