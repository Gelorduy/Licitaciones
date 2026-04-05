<?php

namespace App\Http\Controllers;

use App\Models\Licitacion;
use App\Models\ProposalValidation;
use App\Services\ProposalValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Inertia\Inertia;
use ZipArchive;

class ValidacionController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Validacion/Index', [
            'validations' => $request->user()
                ->licitaciones()
                ->with(['company:id,nombre,rfc', 'validation'])
                ->whereHas('validation')
                ->latest()
                ->get()
                ->map(fn (Licitacion $licitacion) => [
                    'id' => $licitacion->validation?->id,
                    'licitacion_id' => $licitacion->id,
                    'title' => $licitacion->title,
                    'company' => $licitacion->company,
                    'traffic_light' => $licitacion->validation?->traffic_light,
                    'status' => $licitacion->validation?->status,
                    'audited_at' => $licitacion->validation?->audited_at,
                    'override_applied' => $licitacion->validation?->override_applied,
                ]),
            'availableLicitaciones' => $request->user()
                ->licitaciones()
                ->with('company:id,nombre,rfc')
                ->doesntHave('validation')
                ->latest()
                ->get(['id', 'company_id', 'title', 'status']),
        ]);
    }

    public function store(Request $request, ProposalValidationService $validationService): RedirectResponse
    {
        $validated = $request->validate([
            'licitacion_id' => ['required', 'integer'],
        ]);

        $licitacion = $request->user()->licitaciones()->with('validation')->findOrFail($validated['licitacion_id']);

        abort_if($licitacion->validation !== null, 422, 'La licitación ya cuenta con una validación.');

        $report = $validationService->buildReport($licitacion);

        $validation = ProposalValidation::create([
            'user_id' => $request->user()->id,
            'licitacion_id' => $licitacion->id,
            'status' => $report['summary']['traffic_light'] === 'green' ? 'ready_for_export' : 'reviewed',
            'traffic_light' => $report['summary']['traffic_light'],
            'report' => $report,
            'audited_at' => now(),
            'ready_at' => $report['summary']['traffic_light'] === 'green' ? now() : null,
        ]);

        return redirect()->route('validacion.show', $validation->id)->with('success', 'Validación generada.');
    }

    public function show(Request $request, ProposalValidation $validation): Response
    {
        abort_unless($validation->user_id === $request->user()->id, 403);

        $validation->load(['licitacion.company', 'licitacion.regulations', 'licitacion.letterhead']);

        return Inertia::render('Validacion/Show', [
            'validation' => $validation,
        ]);
    }

    public function runAudit(Request $request, ProposalValidation $validation, ProposalValidationService $validationService): RedirectResponse
    {
        abort_unless($validation->user_id === $request->user()->id, 403);

        $validation->load('licitacion');
        $report = $validationService->buildReport($validation->licitacion);

        $status = $report['summary']['traffic_light'] === 'green' || $validation->override_applied
            ? 'ready_for_export'
            : 'reviewed';

        $validation->update([
            'traffic_light' => $validation->override_applied ? 'green' : $report['summary']['traffic_light'],
            'status' => $status,
            'report' => $report,
            'audited_at' => now(),
            'ready_at' => $status === 'ready_for_export' ? now() : null,
        ]);

        return back()->with('success', 'Auditoría ejecutada.');
    }

    public function applyOverride(Request $request, ProposalValidation $validation): RedirectResponse
    {
        abort_unless($validation->user_id === $request->user()->id, 403);

        $payload = $request->validate([
            'override_reason' => ['required', 'string', 'min:10'],
        ]);

        $validation->update([
            'override_applied' => true,
            'override_reason' => $payload['override_reason'],
            'traffic_light' => 'green',
            'status' => 'ready_for_export',
            'ready_at' => now(),
        ]);

        return back()->with('success', 'Override legal aplicado.');
    }

    public function exportUsb(Request $request, ProposalValidation $validation)
    {
        abort_unless($validation->user_id === $request->user()->id, 403);

        abort_unless($validation->status === 'ready_for_export' || $validation->override_applied, 422, 'La validación aún no está lista para exportación.');

        $validation->load(['licitacion.company', 'licitacion.regulations']);

        $tmpPath = tempnam(sys_get_temp_dir(), 'validation_zip_');
        $zipPath = $tmpPath.'.zip';
        @unlink($tmpPath);

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $report = json_encode($validation->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $summary = [
            'licitacion' => $validation->licitacion->title,
            'empresa' => $validation->licitacion->company?->nombre,
            'traffic_light' => $validation->traffic_light,
            'override_applied' => $validation->override_applied,
            'override_reason' => $validation->override_reason,
        ];

        $zip->addFromString('report.json', $report ?: '{}');
        $zip->addFromString('summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->close();

        return response()->download($zipPath, 'validacion-'.$validation->id.'.zip')->deleteFileAfterSend(true);
    }
}
