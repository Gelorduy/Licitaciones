<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\SystemEventLogger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmpresaController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Empresa/Index', [
            'companies' => $request->user()
                ->companies()
                ->withCount(['actas', 'opinionesCumplimiento', 'financialStatements', 'taxDeclarations'])
                ->latest()
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Empresa/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'rfc' => ['required', 'string', 'size:13', 'unique:companies,rfc'],
        ]);

        $company = $request->user()->companies()->create([
            'nombre' => strtoupper($validated['nombre']),
            'rfc' => strtoupper($validated['rfc']),
        ]);

        SystemEventLogger::log(
            'company.created',
            [
                'company_id' => $company->id,
                'rfc' => $company->rfc,
            ],
            $request,
            null,
            Company::class,
            $company->id,
        );

        return redirect()
            ->route('empresa.show', $company)
            ->with('success', 'Empresa creada correctamente.');
    }

    public function show(Request $request, Company $company): Response
    {
        abort_unless($company->user_id === $request->user()->id, 403);

        $company->load([
            'actas' => fn ($query) => $query->with('documentIndex')->latest('fecha_registro')->latest('id'),
            'opinionesCumplimiento' => fn ($query) => $query->with('documentIndex')->latest('fecha_emision')->latest('id'),
            'financialStatements' => fn ($query) => $query->latest('year')->latest('month')->latest('id'),
            'taxDeclarations' => fn ($query) => $query->latest('year')->latest('month')->latest('id'),
            'letterheads' => fn ($query) => $query->latest('is_default')->latest('id'),
        ]);

        $today = Carbon::today();
        $company->setRelation('opinionesCumplimiento', $company->opinionesCumplimiento->map(function ($opinion) use ($today) {
            $vigencia = $opinion->vigencia_calculada ? Carbon::parse($opinion->vigencia_calculada) : null;
            $daysToExpiry = $vigencia ? $today->diffInDays($vigencia, false) : null;

            $expiryStatus = 'ok';
            if ($daysToExpiry !== null && $daysToExpiry < 0) {
                $expiryStatus = 'expired';
            } elseif ($daysToExpiry !== null && $daysToExpiry <= 7) {
                $expiryStatus = 'expiring';
            }

            $opinion->expiry_status = $expiryStatus;
            $opinion->days_to_expiry = $daysToExpiry;

            return $opinion;
        }));

        return Inertia::render('Empresa/Show', [
            'company' => $company,
        ]);
    }
}
