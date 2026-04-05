<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\TaxDeclaration;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeclaracionImpuestoController extends Controller
{
    public function create(Request $request, Company $company): Response
    {
        abort_unless($company->user_id === $request->user()->id, 403);

        return Inertia::render('TaxDeclaration/Create', [
            'company' => $company,
            'formats' => ['pdf', 'xml', 'xlsx', 'xls', 'csv'],
        ]);
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        abort_unless($company->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'periodicity' => ['required', 'in:mensual,anual'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'document' => ['required', 'file', 'mimes:pdf,xml,xlsx,xls,csv', 'max:20480'],
        ]);

        if ($validated['periodicity'] === 'mensual' && empty($validated['month'])) {
            return back()->withErrors(['month' => 'El mes es obligatorio para declaraciones mensuales.']);
        }

        $path = $this->storeDocument($request, $company->id, 'declaraciones', 'document');
        $extension = strtolower($request->file('document')->getClientOriginalExtension());

        $company->taxDeclarations()->create([
            'periodicity' => $validated['periodicity'],
            'year' => $validated['year'],
            'month' => $validated['periodicity'] === 'mensual' ? $validated['month'] : null,
            'format' => in_array($extension, ['pdf', 'xml', 'xlsx', 'xls', 'csv'], true) ? $extension : 'pdf',
            'document_path' => $path,
            'document_original_name' => $request->file('document')->getClientOriginalName(),
        ]);

        return redirect()->route('empresa.show', $company->id)->with('success', 'Declaración registrada.');
    }

    public function edit(Request $request, Company $company, TaxDeclaration $taxDeclaration): Response
    {
        abort_unless($company->user_id === $request->user()->id, 403);
        abort_unless($taxDeclaration->company_id === $company->id, 404);

        return Inertia::render('TaxDeclaration/Edit', [
            'company' => $company,
            'taxDeclaration' => $taxDeclaration,
            'formats' => ['pdf', 'xml', 'xlsx', 'xls', 'csv'],
        ]);
    }

    public function update(Request $request, Company $company, TaxDeclaration $taxDeclaration): RedirectResponse
    {
        abort_unless($company->user_id === $request->user()->id, 403);
        abort_unless($taxDeclaration->company_id === $company->id, 404);

        $validated = $request->validate([
            'periodicity' => ['required', 'in:mensual,anual'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'document' => ['nullable', 'file', 'mimes:pdf,xml,xlsx,xls,csv', 'max:20480'],
        ]);

        if ($validated['periodicity'] === 'mensual' && empty($validated['month'])) {
            return back()->withErrors(['month' => 'El mes es obligatorio para declaraciones mensuales.']);
        }

        $payload = [
            'periodicity' => $validated['periodicity'],
            'year' => $validated['year'],
            'month' => $validated['periodicity'] === 'mensual' ? $validated['month'] : null,
        ];

        if ($request->hasFile('document')) {
            $payload['document_path'] = $this->storeDocument($request, $company->id, 'declaraciones', 'document');
            $payload['document_original_name'] = $request->file('document')->getClientOriginalName();
            $extension = strtolower($request->file('document')->getClientOriginalExtension());
            $payload['format'] = in_array($extension, ['pdf', 'xml', 'xlsx', 'xls', 'csv'], true) ? $extension : 'pdf';
        }

        $taxDeclaration->update($payload);

        return redirect()->route('empresa.show', $company->id)->with('success', 'Declaración actualizada.');
    }

    public function viewFile(Request $request, Company $company, TaxDeclaration $taxDeclaration): StreamedResponse
    {
        abort_unless($company->user_id === $request->user()->id, 403);
        abort_unless($taxDeclaration->company_id === $company->id, 404);

        return $this->streamDocument($taxDeclaration, false);
    }

    public function downloadFile(Request $request, Company $company, TaxDeclaration $taxDeclaration): StreamedResponse
    {
        abort_unless($company->user_id === $request->user()->id, 403);
        abort_unless($taxDeclaration->company_id === $company->id, 404);

        return $this->streamDocument($taxDeclaration, true);
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

    private function streamDocument(TaxDeclaration $taxDeclaration, bool $download): StreamedResponse
    {
        $path = (string) $taxDeclaration->document_path;
        abort_if($path === '', 404);

        $fileName = $taxDeclaration->document_original_name ?: basename($path);
        $disk = Storage::disk('s3')->exists($path) ? 's3' : 'public';
        /** @var FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        if ($download) {
            return $storage->download($path, $fileName);
        }

        return $storage->response($path, $fileName);
    }
}
