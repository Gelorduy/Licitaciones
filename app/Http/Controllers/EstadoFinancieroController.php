<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FinancialStatement;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EstadoFinancieroController extends Controller
{
    public function create(Request $request, Company $company): Response
    {
        abort_unless($company->user_id === $request->user()->id, 403);

        return Inertia::render('FinancialStatement/Create', [
            'company' => $company,
        ]);
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        abort_unless($company->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'periodicity' => ['required', 'in:mensual,anual'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'is_audited' => ['required', 'boolean'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        if ($validated['periodicity'] === 'mensual' && empty($validated['month'])) {
            return back()->withErrors(['month' => 'El mes es obligatorio para estados mensuales.']);
        }

        $path = $this->storeDocument($request, $company->id, 'estados-financieros', 'document');

        $company->financialStatements()->create([
            'periodicity' => $validated['periodicity'],
            'year' => $validated['year'],
            'month' => $validated['periodicity'] === 'mensual' ? $validated['month'] : null,
            'is_audited' => (bool) $validated['is_audited'],
            'document_path' => $path,
            'document_original_name' => $request->file('document')->getClientOriginalName(),
        ]);

        return redirect()->route('empresa.show', $company->id)->with('success', 'Estado financiero registrado.');
    }

    public function edit(Request $request, Company $company, FinancialStatement $financialStatement): Response
    {
        abort_unless($company->user_id === $request->user()->id, 403);
        abort_unless($financialStatement->company_id === $company->id, 404);

        return Inertia::render('FinancialStatement/Edit', [
            'company' => $company,
            'financialStatement' => $financialStatement,
        ]);
    }

    public function update(Request $request, Company $company, FinancialStatement $financialStatement): RedirectResponse
    {
        abort_unless($company->user_id === $request->user()->id, 403);
        abort_unless($financialStatement->company_id === $company->id, 404);

        $validated = $request->validate([
            'periodicity' => ['required', 'in:mensual,anual'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'is_audited' => ['required', 'boolean'],
            'document' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        if ($validated['periodicity'] === 'mensual' && empty($validated['month'])) {
            return back()->withErrors(['month' => 'El mes es obligatorio para estados mensuales.']);
        }

        $payload = [
            'periodicity' => $validated['periodicity'],
            'year' => $validated['year'],
            'month' => $validated['periodicity'] === 'mensual' ? $validated['month'] : null,
            'is_audited' => (bool) $validated['is_audited'],
        ];

        if ($request->hasFile('document')) {
            $payload['document_path'] = $this->storeDocument($request, $company->id, 'estados-financieros', 'document');
            $payload['document_original_name'] = $request->file('document')->getClientOriginalName();
        }

        $financialStatement->update($payload);

        return redirect()->route('empresa.show', $company->id)->with('success', 'Estado financiero actualizado.');
    }

    public function viewFile(Request $request, Company $company, FinancialStatement $financialStatement): StreamedResponse
    {
        abort_unless($company->user_id === $request->user()->id, 403);
        abort_unless($financialStatement->company_id === $company->id, 404);

        return $this->streamDocument($financialStatement, false);
    }

    public function downloadFile(Request $request, Company $company, FinancialStatement $financialStatement): StreamedResponse
    {
        abort_unless($company->user_id === $request->user()->id, 403);
        abort_unless($financialStatement->company_id === $company->id, 404);

        return $this->streamDocument($financialStatement, true);
    }

    private function storeDocument(Request $request, int $companyId, string $folder, string $field): string
    {
        $path = null;

        try {
            $path = $request->file($field)->store('module1/users/'.$request->user()->id.'/empresas/'.$companyId.'/'.$folder, 's3');
        } catch (\Throwable $e) {
            Log::warning('Falling back to local storage for file upload', ['error' => $e->getMessage()]);
        }

        return $path ?: $request->file($field)->store('module1/users/'.$request->user()->id.'/empresas/'.$companyId.'/'.$folder, 'public');
    }

    private function streamDocument(FinancialStatement $financialStatement, bool $download): StreamedResponse
    {
        $path = (string) $financialStatement->document_path;
        abort_if($path === '', 404);

        $fileName = $financialStatement->document_original_name ?: basename($path);
        $disk = Storage::disk('s3')->exists($path) ? 's3' : 'public';
        /** @var FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        if ($download) {
            return $storage->download($path, $fileName);
        }

        return $storage->response($path, $fileName, ['Content-Type' => 'application/pdf']);
    }
}
