<?php

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('email', 'test@example.com')->first();
$company = $user?->companies()->first();

if (! $user || ! $company) {
    echo "missing-seed\n";
    exit(1);
}

$path = 'module1/companies/'.$company->id.'/opiniones/test-opinion-local.pdf';
Illuminate\Support\Facades\Storage::disk('public')->put($path, file_get_contents('/tmp/sample.pdf'));

$op = $company->opinionesCumplimiento()->create([
    'tipo' => 'sat',
    'estado' => 'pendiente',
    'fecha_emision' => now()->toDateString(),
    'documento_path' => $path,
]);

App\Jobs\ProcessUploadedPdfJob::dispatchSync(
    App\Models\OpinionCumplimiento::class,
    $op->id,
    'opinion',
    $user->id,
);

$idx = App\Models\DocumentIndex::where('documentable_type', App\Models\OpinionCumplimiento::class)
    ->where('documentable_id', $op->id)
    ->first();

echo json_encode([
    'disk_detected' => $idx?->storage_disk,
    'status' => $idx?->status,
    'method' => $idx?->extraction_method,
    'chunk_count' => $idx?->chunk_count,
    'has_text' => strlen((string) $idx?->extracted_text) > 0,
], JSON_UNESCAPED_UNICODE).PHP_EOL;
