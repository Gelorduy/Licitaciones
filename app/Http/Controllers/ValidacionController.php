<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\Licitacion;
use App\Models\ProposalValidation;
use App\Services\ProposalValidationService;
use App\Services\ValidationExportService;
use App\Services\WorkflowStateMachine;
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

    public function store(Request $request, ProposalValidationService $validationService, WorkflowStateMachine $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'licitacion_id' => ['required', 'integer'],
        ]);

        $licitacion = $request->user()->licitaciones()->with('validation')->findOrFail($validated['licitacion_id']);

        abort_if($licitacion->validation !== null, 422, 'La licitación ya cuenta con una validación.');

        $report = $validationService->buildReport($licitacion);

        $targetStatus = $report['summary']['traffic_light'] === 'green' ? 'ready_for_export' : 'reviewed';

        $validation = ProposalValidation::create([
            'user_id' => $request->user()->id,
            'licitacion_id' => $licitacion->id,
            'status' => 'draft',
            'traffic_light' => $report['summary']['traffic_light'],
            'report' => $report,
            'audited_at' => now(),
            'ready_at' => null,
        ]);

        $validationService->persistFindings($validation, $report);

        try {
            $workflow->transition(
                model: $validation,
                toState: $targetStatus,
                triggeredByUserId: $request->user()->id,
                reason: 'Creación inicial de validación',
                metadata: ['traffic_light' => $report['summary']['traffic_light']],
            );
        } catch (InvalidStateTransitionException $e) {
            return back()->withErrors(['licitacion_id' => $e->getMessage()]);
        }

        if ($targetStatus === 'ready_for_export') {
            $validation->update(['ready_at' => now()]);
        }

        return redirect()->route('validacion.show', $validation->id)->with('success', 'Validación generada.');
    }

    public function show(Request $request, ProposalValidation $validation): Response
    {
        abort_unless($validation->user_id === $request->user()->id, 403);

        $validation->load(['licitacion.company', 'licitacion.regulations', 'licitacion.letterhead', 'findings.owner']);

        return Inertia::render('Validacion/Show', [
            'validation' => $validation,
        ]);
    }

    public function runAudit(Request $request, ProposalValidation $validation, ProposalValidationService $validationService, WorkflowStateMachine $workflow): RedirectResponse
    {
        abort_unless($validation->user_id === $request->user()->id, 403);

        $validation->load('licitacion');
        $report = $validationService->buildReport($validation->licitacion);

        $status = $report['summary']['traffic_light'] === 'green' || $validation->override_applied
            ? 'ready_for_export'
            : 'reviewed';

        $trafficLight = $validation->override_applied ? 'green' : $report['summary']['traffic_light'];

        $validation->update([
            'traffic_light' => $trafficLight,
            'report' => $report,
            'audited_at' => now(),
            'ready_at' => null,
        ]);

        $validationService->persistFindings($validation, $report);

        try {
            $workflow->transition(
                model: $validation,
                toState: $status,
                triggeredByUserId: $request->user()->id,
                reason: 'Reejecución de auditoría',
                metadata: [
                    'traffic_light' => $trafficLight,
                    'issues' => count($report['issues'] ?? []),
                    'warnings' => count($report['warnings'] ?? []),
                ],
            );
        } catch (InvalidStateTransitionException $e) {
            return back()->withErrors(['audit' => $e->getMessage()]);
        }

        if ($status === 'ready_for_export') {
            $validation->update(['ready_at' => now()]);
        }

        return back()->with('success', 'Auditoría ejecutada.');
    }

    public function applyOverride(Request $request, ProposalValidation $validation, WorkflowStateMachine $workflow): RedirectResponse
    {
        abort_unless($validation->user_id === $request->user()->id, 403);

        $payload = $request->validate([
            'override_reason' => ['required', 'string', 'min:10'],
        ]);

        $validation->update([
            'override_applied' => true,
            'override_reason' => $payload['override_reason'],
            'traffic_light' => 'green',
            'ready_at' => null,
        ]);

        try {
            $workflow->transition(
                model: $validation,
                toState: 'ready_for_export',
                triggeredByUserId: $request->user()->id,
                reason: 'Override legal aplicado',
                metadata: ['override_reason' => $payload['override_reason']],
            );
        } catch (InvalidStateTransitionException $e) {
            return back()->withErrors(['override_reason' => $e->getMessage()]);
        }

        $validation->update(['ready_at' => now()]);

        return back()->with('success', 'Override legal aplicado.');
    }

    public function exportUsb(Request $request, ProposalValidation $validation, ValidationExportService $exportService)
    {
        abort_unless($validation->user_id === $request->user()->id, 403);

        abort_unless($validation->status === 'ready_for_export' || $validation->override_applied, 422, 'La validación aún no está lista para exportación.');

        $validation->load(['licitacion.company', 'licitacion.regulations', 'findings']);

        $tmpPath = tempnam(sys_get_temp_dir(), 'validation_zip_');
        $zipPath = $tmpPath.'.zip';
        @unlink($tmpPath);

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $xlsxPath = $exportService->createFindingsXlsxTempFile($validation);

        $report = json_encode($validation->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $summary = [
            'licitacion' => $validation->licitacion->title,
            'empresa' => $validation->licitacion->company?->nombre,
            'traffic_light' => $validation->traffic_light,
            'override_applied' => $validation->override_applied,
            'override_reason' => $validation->override_reason,
            'findings_count' => $validation->findings->count(),
            'critical_findings_count' => $validation->findings->where('severity', 'critical')->count(),
            'warning_findings_count' => $validation->findings->where('severity', 'warning')->count(),
        ];

        $zip->addFromString('report.json', $report ?: '{}');
        $zip->addFromString('findings.json', $validation->findings->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('structured_findings.json', json_encode($validation->report['structured_findings'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFile($xlsxPath, 'Reporte_Validacion_Licitacion.xlsx');
        $zip->close();

        @unlink($xlsxPath);

        return response()->download($zipPath, 'validacion-'.$validation->id.'.zip')->deleteFileAfterSend(true);
    }

    public function exportXlsx(Request $request, ProposalValidation $validation, ValidationExportService $exportService)
    {
        abort_unless($validation->user_id === $request->user()->id, 403);

        abort_unless($validation->status === 'ready_for_export' || $validation->override_applied, 422, 'La validación aún no está lista para exportación.');

        $validation->load(['findings']);

        $xlsxPath = $exportService->createFindingsXlsxTempFile($validation);

        return response()->download($xlsxPath, 'Reporte_Validacion_Licitacion_'.$validation->id.'.xlsx')->deleteFileAfterSend(true);
    }
}
