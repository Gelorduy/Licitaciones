<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\RegulacionController;
use App\Http\Controllers\LicitacionController;
use App\Http\Controllers\ValidacionController;
use App\Http\Controllers\ActaController;
use App\Http\Controllers\DeclaracionImpuestoController;
use App\Http\Controllers\EstadoFinancieroController;
use App\Http\Controllers\HojaMembretadaController;
use App\Http\Controllers\OpinionCumplimientoController;
use App\Models\Acta;
use App\Models\DocumentIndex;
use App\Models\Licitacion;
use App\Models\OpinionCumplimiento;
use App\Models\ProposalValidation;
use Carbon\Carbon;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function (Request $request) {
    $user = $request->user();
    $todayStart = Carbon::today();

    $companiesCount = $user->companies()->count();
    $companiesToday = $user->companies()->where('created_at', '>=', $todayStart)->count();

    $actasCount = Acta::query()
        ->whereHas('company', fn ($query) => $query->where('user_id', $user->id))
        ->count();
    $actasToday = Acta::query()
        ->whereHas('company', fn ($query) => $query->where('user_id', $user->id))
        ->where('created_at', '>=', $todayStart)
        ->count();

    $opinionesCount = OpinionCumplimiento::query()
        ->whereHas('company', fn ($query) => $query->where('user_id', $user->id))
        ->count();
    $opinionesToday = OpinionCumplimiento::query()
        ->whereHas('company', fn ($query) => $query->where('user_id', $user->id))
        ->where('created_at', '>=', $todayStart)
        ->count();

    $regulationsCount = $user->regulations()->count();
    $regulationsToday = $user->regulations()->where('created_at', '>=', $todayStart)->count();
    $licitacionesCount = Licitacion::query()->where('user_id', $user->id)->count();
    $licitacionesToday = Licitacion::query()->where('user_id', $user->id)->where('created_at', '>=', $todayStart)->count();

    $documentsTotal = $actasCount + $opinionesCount + $regulationsCount;
    $documentsToday = $actasToday + $opinionesToday + $regulationsToday;
    $validationsCount = ProposalValidation::query()->where('user_id', $user->id)->count();
    $validationsToday = ProposalValidation::query()->where('user_id', $user->id)->where('created_at', '>=', $todayStart)->count();
    $readyValidations = ProposalValidation::query()->where('user_id', $user->id)->where('status', 'ready_for_export')->count();
    $openObservations = ProposalValidation::query()
        ->where('user_id', $user->id)
        ->whereIn('traffic_light', ['yellow', 'red'])
        ->count();

    $indexes = DocumentIndex::query()
        ->where('user_id', $user->id)
        ->selectRaw("SUM(CASE WHEN status = 'indexed' THEN 1 ELSE 0 END) as indexed_count")
        ->selectRaw("SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed_count")
        ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
        ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count")
        ->selectRaw("SUM(CASE WHEN status = 'indexed' AND created_at >= ? THEN 1 ELSE 0 END) as indexed_today", [$todayStart])
        ->selectRaw("SUM(CASE WHEN status = 'processed' AND created_at >= ? THEN 1 ELSE 0 END) as processed_today", [$todayStart])
        ->selectRaw("SUM(CASE WHEN status = 'failed' AND created_at >= ? THEN 1 ELSE 0 END) as failed_today", [$todayStart])
        ->selectRaw("SUM(CASE WHEN status = 'pending' AND created_at >= ? THEN 1 ELSE 0 END) as pending_today", [$todayStart])
        ->first();

    $lastUpdatedAt = collect([
        $user->companies()->max('updated_at'),
        Acta::query()->whereHas('company', fn ($query) => $query->where('user_id', $user->id))->max('updated_at'),
        OpinionCumplimiento::query()->whereHas('company', fn ($query) => $query->where('user_id', $user->id))->max('updated_at'),
        $user->regulations()->max('updated_at'),
        DocumentIndex::query()->where('user_id', $user->id)->max('updated_at'),
    ])->filter()->max();

    return Inertia::render('Dashboard', [
        'overview' => [
            'companies' => $companiesCount,
            'documents' => $documentsTotal,
            'indexed' => (int) ($indexes?->indexed_count ?? 0),
            'processed' => (int) ($indexes?->processed_count ?? 0),
            'pending' => (int) ($indexes?->pending_count ?? 0),
            'failed' => (int) ($indexes?->failed_count ?? 0),
            'trends' => [
                'companies' => $companiesToday,
                'documents' => $documentsToday,
                'indexed' => (int) ($indexes?->indexed_today ?? 0),
                'processed' => (int) ($indexes?->processed_today ?? 0),
                'pending' => (int) ($indexes?->pending_today ?? 0),
                'failed' => (int) ($indexes?->failed_today ?? 0),
            ],
            'last_updated_at' => $lastUpdatedAt,
        ],
        'modules' => [
            [
                'title' => 'Modulo 1',
                'subtitle' => 'Gestion Empresarial',
                'status' => 'activo',
                'route' => 'empresa.index',
                'action' => 'Abrir modulo',
                'metrics' => [
                    ['label' => 'Empresas', 'value' => $companiesCount, 'today' => $companiesToday],
                    ['label' => 'Actas', 'value' => $actasCount, 'today' => $actasToday],
                    ['label' => 'Opiniones', 'value' => $opinionesCount, 'today' => $opinionesToday],
                ],
            ],
            [
                'title' => 'Modulo 2',
                'subtitle' => 'Regulaciones Aplicables',
                'status' => 'activo',
                'route' => 'regulacion.index',
                'action' => 'Abrir modulo',
                'metrics' => [
                    ['label' => 'Regulaciones', 'value' => $regulationsCount, 'today' => $regulationsToday],
                    ['label' => 'Indexadas', 'value' => (int) ($indexes?->indexed_count ?? 0), 'today' => (int) ($indexes?->indexed_today ?? 0)],
                    ['label' => 'Con error', 'value' => (int) ($indexes?->failed_count ?? 0), 'today' => (int) ($indexes?->failed_today ?? 0)],
                ],
            ],
            [
                'title' => 'Modulo 3',
                'subtitle' => 'Generacion Documental',
                'status' => 'activo',
                'route' => 'licitacion.index',
                'action' => 'Abrir modulo',
                'metrics' => [
                    ['label' => 'Licitaciones creadas', 'value' => $licitacionesCount, 'today' => $licitacionesToday],
                    ['label' => 'Checklist IA', 'value' => $licitacionesCount, 'today' => $licitacionesToday],
                    ['label' => 'Documentos generados', 'value' => 0, 'today' => 0],
                ],
            ],
            [
                'title' => 'Modulo 4',
                'subtitle' => 'Validacion y Cierre',
                'status' => 'activo',
                'route' => 'validacion.index',
                'action' => 'Abrir modulo',
                'metrics' => [
                    ['label' => 'Expedientes validados', 'value' => $validationsCount, 'today' => $validationsToday],
                    ['label' => 'Observaciones abiertas', 'value' => $openObservations, 'today' => 0],
                    ['label' => 'Expedientes listos', 'value' => $readyValidations, 'today' => 0],
                ],
            ],
        ],
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Module routes
    Route::get('/empresas', [EmpresaController::class, 'index'])->name('empresa.index');
    Route::get('/empresas/crear', [EmpresaController::class, 'create'])->name('empresa.create');
    Route::post('/empresas', [EmpresaController::class, 'store'])->name('empresa.store');
    Route::get('/empresas/{company}', [EmpresaController::class, 'show'])->name('empresa.show');
    Route::get('/empresas/{company}/actas/crear', [ActaController::class, 'create'])->name('acta.create');
    Route::post('/empresas/{company}/actas', [ActaController::class, 'store'])->name('acta.store');
    Route::get('/empresas/{company}/actas/{acta}/editar', [ActaController::class, 'edit'])->name('acta.edit');
    Route::put('/empresas/{company}/actas/{acta}', [ActaController::class, 'update'])->name('acta.update');
    Route::post('/empresas/{company}/actas/{acta}/reextraer', [ActaController::class, 'reextract'])->name('acta.reextract');
    Route::get('/empresas/{company}/actas/{acta}/trace', [ActaController::class, 'downloadTrace'])->name('acta.trace.download');
    Route::get('/empresas/{company}/actas/{acta}/archivo', [ActaController::class, 'viewFile'])->name('acta.file.view');
    Route::get('/empresas/{company}/actas/{acta}/archivo/descargar', [ActaController::class, 'downloadFile'])->name('acta.file.download');
    Route::get('/empresas/{company}/actas/{acta}/texto-ocr', [ActaController::class, 'viewExtractedText'])->name('acta.text.view');
    Route::get('/empresas/{company}/actas/{acta}/pinecone', [ActaController::class, 'viewPineconeData'])->name('acta.pinecone.view');
    Route::get('/empresas/{company}/opiniones/crear', [OpinionCumplimientoController::class, 'create'])->name('opinion.create');
    Route::post('/empresas/{company}/opiniones', [OpinionCumplimientoController::class, 'store'])->name('opinion.store');
    Route::get('/empresas/{company}/opiniones/{opinion}/editar', [OpinionCumplimientoController::class, 'edit'])->name('opinion.edit');
    Route::put('/empresas/{company}/opiniones/{opinion}', [OpinionCumplimientoController::class, 'update'])->name('opinion.update');
    Route::get('/empresas/{company}/opiniones/{opinion}/archivo', [OpinionCumplimientoController::class, 'viewFile'])->name('opinion.file.view');
    Route::get('/empresas/{company}/opiniones/{opinion}/archivo/descargar', [OpinionCumplimientoController::class, 'downloadFile'])->name('opinion.file.download');
    Route::get('/empresas/{company}/opiniones/{opinion}/texto-ocr', [OpinionCumplimientoController::class, 'viewExtractedText'])->name('opinion.text.view');

    Route::get('/empresas/{company}/estados-financieros/crear', [EstadoFinancieroController::class, 'create'])->name('estado-financiero.create');
    Route::post('/empresas/{company}/estados-financieros', [EstadoFinancieroController::class, 'store'])->name('estado-financiero.store');
    Route::get('/empresas/{company}/estados-financieros/{financialStatement}/editar', [EstadoFinancieroController::class, 'edit'])->name('estado-financiero.edit');
    Route::put('/empresas/{company}/estados-financieros/{financialStatement}', [EstadoFinancieroController::class, 'update'])->name('estado-financiero.update');
    Route::get('/empresas/{company}/estados-financieros/{financialStatement}/archivo', [EstadoFinancieroController::class, 'viewFile'])->name('estado-financiero.file.view');
    Route::get('/empresas/{company}/estados-financieros/{financialStatement}/archivo/descargar', [EstadoFinancieroController::class, 'downloadFile'])->name('estado-financiero.file.download');

    Route::get('/empresas/{company}/declaraciones/crear', [DeclaracionImpuestoController::class, 'create'])->name('declaracion.create');
    Route::post('/empresas/{company}/declaraciones', [DeclaracionImpuestoController::class, 'store'])->name('declaracion.store');
    Route::get('/empresas/{company}/declaraciones/{taxDeclaration}/editar', [DeclaracionImpuestoController::class, 'edit'])->name('declaracion.edit');
    Route::put('/empresas/{company}/declaraciones/{taxDeclaration}', [DeclaracionImpuestoController::class, 'update'])->name('declaracion.update');
    Route::get('/empresas/{company}/declaraciones/{taxDeclaration}/archivo', [DeclaracionImpuestoController::class, 'viewFile'])->name('declaracion.file.view');
    Route::get('/empresas/{company}/declaraciones/{taxDeclaration}/archivo/descargar', [DeclaracionImpuestoController::class, 'downloadFile'])->name('declaracion.file.download');

    Route::get('/empresas/{company}/hoja-membretada/crear', [HojaMembretadaController::class, 'create'])->name('letterhead.create');
    Route::post('/empresas/{company}/hoja-membretada', [HojaMembretadaController::class, 'store'])->name('letterhead.store');
    Route::get('/empresas/{company}/hoja-membretada/{letterhead}/editar', [HojaMembretadaController::class, 'edit'])->name('letterhead.edit');
    Route::put('/empresas/{company}/hoja-membretada/{letterhead}', [HojaMembretadaController::class, 'update'])->name('letterhead.update');

    Route::get('/regulaciones', [RegulacionController::class, 'index'])->name('regulacion.index');
    Route::get('/regulaciones/crear', [RegulacionController::class, 'create'])->name('regulacion.create');
    Route::post('/regulaciones', [RegulacionController::class, 'store'])->name('regulacion.store');
    Route::get('/regulaciones/{regulation}/editar', [RegulacionController::class, 'edit'])->name('regulacion.edit');
    Route::put('/regulaciones/{regulation}', [RegulacionController::class, 'update'])->name('regulacion.update');
    Route::get('/regulaciones/{regulation}/archivo', [RegulacionController::class, 'viewFile'])->name('regulacion.file.view');
    Route::get('/regulaciones/{regulation}/archivo/descargar', [RegulacionController::class, 'downloadFile'])->name('regulacion.file.download');
    Route::get('/regulaciones/{regulation}/texto-ocr', [RegulacionController::class, 'viewExtractedText'])->name('regulacion.text.view');

    Route::get('/licitaciones', [LicitacionController::class, 'index'])->name('licitacion.index');
    Route::get('/licitaciones/crear', [LicitacionController::class, 'create'])->name('licitacion.create');
    Route::post('/licitaciones', [LicitacionController::class, 'store'])->name('licitacion.store');
    Route::get('/licitaciones/{licitacion}/editar', [LicitacionController::class, 'edit'])->name('licitacion.edit');
    Route::put('/licitaciones/{licitacion}', [LicitacionController::class, 'update'])->name('licitacion.update');
    Route::post('/licitaciones/{licitacion}/send-for-approval', [LicitacionController::class, 'sendForApproval'])->name('licitacion.send-for-approval');
    Route::post('/licitaciones/{licitacion}/approve-submission', [LicitacionController::class, 'approveSubmission'])->name('licitacion.approve-submission');
    Route::get('/licitaciones/{licitacion}', [LicitacionController::class, 'show'])->name('licitacion.show');
    Route::get('/validacion', [ValidacionController::class, 'index'])->name('validacion.index');
    Route::post('/validacion', [ValidacionController::class, 'store'])->name('validacion.store');
    Route::get('/validacion/{validation}', [ValidacionController::class, 'show'])->name('validacion.show');
    Route::post('/validacion/{validation}/auditar', [ValidacionController::class, 'runAudit'])->name('validacion.audit');
    Route::post('/validacion/{validation}/override', [ValidacionController::class, 'applyOverride'])->name('validacion.override');
    Route::get('/validacion/{validation}/export-xlsx', [ValidacionController::class, 'exportXlsx'])->name('validacion.export-xlsx');
    Route::get('/validacion/{validation}/export-usb', [ValidacionController::class, 'exportUsb'])->name('validacion.export-usb');
});

require __DIR__.'/auth.php';
