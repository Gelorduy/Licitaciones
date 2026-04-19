<?php

namespace App\Console\Commands;

use App\Jobs\ProcessUploadedPdfJob;
use App\Jobs\VectorIndexDocumentJob;
use App\Models\DocumentIndex;
use Illuminate\Console\Command;

class RequeueDocumentIndexing extends Command
{
    protected $signature = 'documents:requeue-indexing
                            {--status=processed : Requeue records with this status (processed or failed)}
                            {--dry-run : List matching records without dispatching jobs}';

    protected $description = 'Requeue document indexing jobs for records that have been extracted but not yet vector-indexed, or that failed.';

    public function handle(): int
    {
        $status = $this->option('status');
        $dryRun = $this->option('dry-run');

        if (! in_array($status, ['processed', 'failed'], true)) {
            $this->error("Invalid --status value. Use 'processed' or 'failed'.");

            return self::FAILURE;
        }

        $records = DocumentIndex::query()
            ->where('status', $status)
            ->get();

        if ($records->isEmpty()) {
            $this->info("No records with status '{$status}' found.");

            return self::SUCCESS;
        }

        $this->info("Found {$records->count()} record(s) with status '{$status}'.");

        if ($dryRun) {
            foreach ($records as $record) {
                $hasText = ! empty($record->index_text) || ! empty($record->extracted_text);
                $jobType = ($status === 'processed' && $hasText) ? 'VectorIndexDocumentJob' : 'ProcessUploadedPdfJob';
                $this->line("  [DRY-RUN] id={$record->id} type={$record->document_type} doc_id={$record->documentable_id} job={$jobType}");
            }

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($records as $record) {
            // For 'processed' records that already have extracted text, use the
            // lightweight VectorIndexDocumentJob to skip re-running OCR.
            // For 'failed' records (no text), run the full pipeline from scratch.
            if ($status === 'processed' && (! empty($record->index_text) || ! empty($record->extracted_text))) {
                VectorIndexDocumentJob::dispatch($record->id);
                $this->line("  Dispatched VectorIndexDocumentJob for id={$record->id} ({$record->document_type} #{$record->documentable_id})");
            } else {
                if (! class_exists($record->documentable_type)) {
                    $this->warn("  Skipping id={$record->id}: class '{$record->documentable_type}' not found.");
                    continue;
                }

                ProcessUploadedPdfJob::dispatch(
                    $record->documentable_type,
                    $record->documentable_id,
                    $record->document_type,
                    (int) $record->user_id,
                );
                $this->line("  Dispatched ProcessUploadedPdfJob for id={$record->id} ({$record->document_type} #{$record->documentable_id})");
            }

            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} job(s) to the queue.");

        return self::SUCCESS;
    }
}
