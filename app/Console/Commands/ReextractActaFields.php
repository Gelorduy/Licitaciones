<?php

namespace App\Console\Commands;

use App\Jobs\ProcessUploadedPdfJob;
use App\Models\Acta;
use Illuminate\Console\Command;

class ReextractActaFields extends Command
{
    protected $signature = 'actas:reextract-fields
                            {--company-id= : Filter by company id}
                            {--acta-id= : Reextract a single acta id}
                            {--sync : Run extraction synchronously in this process}
                            {--dry-run : Show what would be processed}';

    protected $description = 'Re-run OCR metadata extraction for actas to populate structured legal fields and missing-field alerts.';

    public function handle(): int
    {
        $query = Acta::query()->whereNotNull('documento_path');

        if ($companyId = $this->option('company-id')) {
            $query->where('company_id', (int) $companyId);
        }

        if ($actaId = $this->option('acta-id')) {
            $query->whereKey((int) $actaId);
        }

        $actas = $query
            ->with('company:id,user_id')
            ->get(['id', 'company_id']);

        if ($actas->isEmpty()) {
            $this->info('No se encontraron actas con archivo para reprocesar.');

            return self::SUCCESS;
        }

        $this->info('Actas encontradas: '.$actas->count());

        if ($this->option('dry-run')) {
            foreach ($actas as $acta) {
                $this->line("  [DRY-RUN] acta_id={$acta->id} company_id={$acta->company_id}");
            }

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');

        foreach ($actas as $acta) {
            $userId = (int) ($acta->company?->user_id ?? 0);

            if ($userId <= 0) {
                $this->warn("  Saltando acta_id={$acta->id}: no se pudo resolver user_id.");
                continue;
            }

            $job = new ProcessUploadedPdfJob(Acta::class, (int) $acta->id, 'acta', $userId);

            if ($sync) {
                app()->call([$job, 'handle']);
                $this->line("  Procesada (sync): acta_id={$acta->id}");
            } else {
                ProcessUploadedPdfJob::dispatch(Acta::class, (int) $acta->id, 'acta', $userId);
                $this->line("  Encolada: acta_id={$acta->id}");
            }
        }

        $this->info($sync ? 'Reextracción finalizada.' : 'Reextracción encolada.');

        return self::SUCCESS;
    }
}
