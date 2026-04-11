<?php

namespace App\Services;

use App\Models\ProposalValidation;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class ValidationExportService
{
    public function buildFindingsRows(ProposalValidation $validation): array
    {
        $persisted = $validation->findings;
        if ($persisted->isNotEmpty()) {
            return $persisted->map(fn ($finding) => [
                'documento' => $finding->rule_code.' · '.$finding->category,
                'gravedad' => $this->mapSeverity($finding->severity),
                'error' => $finding->message,
                'propuesta_solucion' => $this->defaultSuggestionForSeverity($finding->severity),
            ])->values()->all();
        }

        $legacyCritical = collect($validation->report['issues'] ?? [])->map(fn ($message) => [
            'documento' => 'LEGACY · cumplimiento',
            'gravedad' => 'Alta',
            'error' => (string) $message,
            'propuesta_solucion' => $this->defaultSuggestionForSeverity('critical'),
        ]);

        $legacyWarnings = collect($validation->report['warnings'] ?? [])->map(fn ($message) => [
            'documento' => 'LEGACY · seguimiento',
            'gravedad' => 'Media',
            'error' => (string) $message,
            'propuesta_solucion' => $this->defaultSuggestionForSeverity('warning'),
        ]);

        return $legacyCritical->concat($legacyWarnings)->values()->all();
    }

    public function createFindingsXlsxTempFile(ProposalValidation $validation): string
    {
        $rows = $this->buildFindingsRows($validation);

        $tmpPath = tempnam(sys_get_temp_dir(), 'validation_xlsx_');
        $xlsxPath = $tmpPath.'.xlsx';
        @unlink($tmpPath);

        $writer = new Writer();
        $writer->openToFile($xlsxPath);

        $writer->addRow(Row::fromValues([
            'Documento / Requisito',
            'Gravedad',
            'Error Detectado',
            'Propuesta de Solucion',
        ]));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues([
                $row['documento'] ?? '',
                $row['gravedad'] ?? '',
                $row['error'] ?? '',
                $row['propuesta_solucion'] ?? '',
            ]));
        }

        $writer->close();

        return $xlsxPath;
    }

    private function mapSeverity(string $severity): string
    {
        return match ($severity) {
            'critical' => 'Alta',
            'warning' => 'Media',
            default => 'Baja',
        };
    }

    private function defaultSuggestionForSeverity(string $severity): string
    {
        return match ($severity) {
            'critical' => 'Corregir este incumplimiento antes de presentar la propuesta y volver a ejecutar auditoria.',
            'warning' => 'Revisar este punto y documentar la correccion para evitar riesgo operativo o legal.',
            default => 'Revisar y confirmar el hallazgo.',
        };
    }
}
