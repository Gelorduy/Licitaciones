<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyLetterhead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HojaMembretadaController extends Controller
{
    public function create(Request $request, Company $company): Response
    {
        abort_unless($company->user_id === $request->user()->id, 403);

        return Inertia::render('Letterhead/Create', [
            'company' => $company,
        ]);
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        abort_unless($company->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'body_template' => ['nullable', 'string'],
            'is_default' => ['required', 'boolean'],
        ]);

        if ($validated['is_default']) {
            $company->letterheads()->update(['is_default' => false]);
        }

        $company->letterheads()->create($validated);

        return redirect()->route('empresa.show', $company->id)->with('success', 'Hoja membretada registrada.');
    }

    public function edit(Request $request, Company $company, CompanyLetterhead $letterhead): Response
    {
        abort_unless($company->user_id === $request->user()->id, 403);
        abort_unless($letterhead->company_id === $company->id, 404);

        return Inertia::render('Letterhead/Edit', [
            'company' => $company,
            'letterhead' => $letterhead,
        ]);
    }

    public function update(Request $request, Company $company, CompanyLetterhead $letterhead): RedirectResponse
    {
        abort_unless($company->user_id === $request->user()->id, 403);
        abort_unless($letterhead->company_id === $company->id, 404);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'body_template' => ['nullable', 'string'],
            'is_default' => ['required', 'boolean'],
        ]);

        if ($validated['is_default']) {
            $company->letterheads()->where('id', '!=', $letterhead->id)->update(['is_default' => false]);
        }

        $letterhead->update($validated);

        return redirect()->route('empresa.show', $company->id)->with('success', 'Hoja membretada actualizada.');
    }
}
