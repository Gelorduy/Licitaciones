<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class DocumentProcessingTraceLogger
{
    private array $trace;

    private string $latestPath;

    private string $archivedPath;

    public function __construct(
        private readonly string $documentType,
        private readonly int $documentId,
        private readonly string $documentClass,
        private readonly int $userId,
        private readonly ?int $companyId = null,
        private readonly string $disk = 'local',
    ) {
        $timestamp = Carbon::now()->format('Ymd_His_u');
        $basePath = sprintf('document-traces/%s/%d', $documentType, $documentId);

        $this->latestPath = $basePath.'/latest.json';
        $this->archivedPath = $basePath.'/trace_'.$timestamp.'.json';
        $this->trace = [
            'document_type' => $documentType,
            'document_id' => $documentId,
            'document_class' => $documentClass,
            'user_id' => $userId,
            'company_id' => $companyId,
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'completed_at' => null,
            'summary' => [],
            'steps' => [],
        ];

        $this->persist(false);
    }

    public function record(string $step, string $status = 'info', array $data = []): void
    {
        $this->trace['steps'][] = [
            'at' => now()->toIso8601String(),
            'step' => $step,
            'status' => $status,
            'data' => $data,
        ];
        $this->trace['updated_at'] = now()->toIso8601String();

        $this->persist(false);
    }

    public function finalize(string $status, array $summary = []): array
    {
        $this->trace['status'] = $status;
        $this->trace['summary'] = $summary;
        $this->trace['updated_at'] = now()->toIso8601String();
        $this->trace['completed_at'] = now()->toIso8601String();

        $this->persist(true);

        return $this->reference();
    }

    public function reference(): array
    {
        return [
            'disk' => $this->disk,
            'latest_path' => $this->latestPath,
            'archived_path' => $this->archivedPath,
            'status' => $this->trace['status'],
            'step_count' => count($this->trace['steps']),
            'updated_at' => $this->trace['updated_at'],
            'completed_at' => $this->trace['completed_at'],
        ];
    }

    private function persist(bool $archive): void
    {
        $payload = json_encode($this->trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($payload)) {
            return;
        }

        $this->writeFile($this->latestPath, $payload);

        if ($archive) {
            $this->writeFile($this->archivedPath, $payload);
        }
    }

    private function writeFile(string $relativePath, string $payload): void
    {
        $absolutePath = storage_path('app/private/'.$relativePath);
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($absolutePath, $payload);
    }
}