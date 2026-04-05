<?php

namespace App\Services;

use App\Models\Licitacion;
use App\Models\ProposalValidation;
use Carbon\Carbon;

class ProposalValidationService
{
    public function buildReport(Licitacion $licitacion): array
    {
        $licitacion->loadMissing(['company.actas', 'company.opinionesCumplimiento', 'company.financialStatements', 'company.taxDeclarations', 'regulations', 'letterhead']);

        $today = Carbon::today();
        $issues = [];
        $warnings = [];
        $checks = [];

        $checklist = collect($licitacion->checklist ?? []);
        $uncheckedChecklist = $checklist->filter(fn ($item) => ! ($item['checked'] ?? false))->values();

        $checks[] = [
            'label' => 'Bases cargadas',
            'passed' => ! empty($licitacion->bases_document_path),
            'detail' => $licitacion->bases_document_original_name ?: 'Sin bases adjuntas',
        ];

        $checks[] = [
            'label' => 'Checklist revisado',
            'passed' => $uncheckedChecklist->isEmpty(),
            'detail' => $uncheckedChecklist->isEmpty()
                ? 'Todos los puntos iniciales aparecen marcados.'
                : 'Hay '.$uncheckedChecklist->count().' punto(s) pendientes en checklist.',
        ];

        $checks[] = [
            'label' => 'Regulaciones asignadas',
            'passed' => $licitacion->regulations->isNotEmpty(),
            'detail' => $licitacion->regulations->isNotEmpty()
                ? $licitacion->regulations->count().' regulación(es) vinculadas.'
                : 'No hay regulaciones vinculadas.',
        ];

        $checks[] = [
            'label' => 'Hoja membretada seleccionada',
            'passed' => $licitacion->letterhead !== null,
            'detail' => $licitacion->letterhead?->title ?: 'No seleccionada.',
        ];

        $company = $licitacion->company;

        $checks[] = [
            'label' => 'Actas corporativas',
            'passed' => $company->actas->isNotEmpty(),
            'detail' => $company->actas->isNotEmpty() ? $company->actas->count().' acta(s).' : 'No hay actas registradas.',
        ];

        $checks[] = [
            'label' => 'Estados financieros',
            'passed' => $company->financialStatements->isNotEmpty(),
            'detail' => $company->financialStatements->isNotEmpty() ? $company->financialStatements->count().' estado(s).' : 'No hay estados financieros.',
        ];

        $checks[] = [
            'label' => 'Declaraciones de impuestos',
            'passed' => $company->taxDeclarations->isNotEmpty(),
            'detail' => $company->taxDeclarations->isNotEmpty() ? $company->taxDeclarations->count().' declaración(es).' : 'No hay declaraciones.',
        ];

        $opinions = $company->opinionesCumplimiento;
        $checks[] = [
            'label' => 'Opiniones de cumplimiento',
            'passed' => $opinions->isNotEmpty(),
            'detail' => $opinions->isNotEmpty() ? $opinions->count().' opinión(es).' : 'No hay opiniones de cumplimiento.',
        ];

        foreach ($checks as $check) {
            if (! $check['passed']) {
                $issues[] = $check['label'].': '.$check['detail'];
            }
        }

        foreach ($opinions as $opinion) {
            $vigencia = $opinion->vigencia_calculada ? Carbon::parse($opinion->vigencia_calculada) : null;
            $daysToExpiry = $vigencia ? $today->diffInDays($vigencia, false) : null;

            if ($daysToExpiry !== null && $daysToExpiry < 0) {
                $issues[] = 'Opinión '.$opinion->tipo.' vencida desde hace '.abs($daysToExpiry).' día(s).';
            } elseif ($daysToExpiry !== null && $daysToExpiry <= 7) {
                $warnings[] = 'Opinión '.$opinion->tipo.' por vencer en '.$daysToExpiry.' día(s).';
            }
        }

        foreach ($uncheckedChecklist as $item) {
            $warnings[] = 'Checklist pendiente: '.($item['label'] ?? 'Punto sin descripción');
        }

        $findings = [];

        foreach ($issues as $issue) {
            $findings[] = [
                'severity' => 'critical',
                'category' => 'cumplimiento',
                'rule_code' => 'CHK-CRIT',
                'message' => $issue,
            ];
        }

        foreach ($warnings as $warning) {
            $findings[] = [
                'severity' => 'warning',
                'category' => 'seguimiento',
                'rule_code' => 'CHK-WARN',
                'message' => $warning,
            ];
        }

        $trafficLight = 'green';
        if (count($issues) > 0) {
            $trafficLight = 'red';
        } elseif (count($warnings) > 0) {
            $trafficLight = 'yellow';
        }

        return [
            'summary' => [
                'checks_total' => count($checks),
                'checks_passed' => collect($checks)->where('passed', true)->count(),
                'issues_count' => count($issues),
                'warnings_count' => count($warnings),
                'traffic_light' => $trafficLight,
            ],
            'checks' => $checks,
            'findings' => $findings,
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    public function persistFindings(ProposalValidation $validation, array $report): void
    {
        $validation->findings()->delete();

        foreach ($report['findings'] ?? [] as $finding) {
            $validation->findings()->create([
                'severity' => $finding['severity'] ?? 'info',
                'category' => $finding['category'] ?? 'general',
                'rule_code' => $finding['rule_code'] ?? 'GEN-000',
                'message' => $finding['message'] ?? 'Hallazgo sin detalle.',
                'status' => 'open',
            ]);
        }
    }
}
