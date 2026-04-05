<?php

namespace App\Services;

use App\Models\Licitacion;
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

        $trafficLight = 'green';
        if (! empty($issues)) {
            $trafficLight = 'red';
        } elseif (! empty($warnings)) {
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
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }
}
