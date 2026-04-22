<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(): Response
    {
        $companies = Company::query()
            ->withCount(['partners', 'invoices'])
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'cui' => $company->cui,
                'db_host' => $company->db_host,
                'db_port' => $company->db_port,
                'db_database' => $company->db_database,
                'partners_count' => $company->partners_count,
                'invoices_count' => $company->invoices_count,
                'last_synced_at' => $company->last_synced_at?->toDateTimeString(),
            ]);

        return Inertia::render('companies/index', [
            'companies' => $companies,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('companies/create');
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        Company::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Companie adăugată.']);

        return to_route('companies.index');
    }

    public function edit(Company $company): Response
    {
        return Inertia::render('companies/edit', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'cui' => $company->cui,
                'db_driver' => $company->db_driver,
                'db_host' => $company->db_host,
                'db_port' => $company->db_port,
                'db_database' => $company->db_database,
                'db_username' => $company->db_username,
            ],
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        $data = $request->validated();

        if (blank($data['db_password'] ?? null)) {
            unset($data['db_password']);
        }

        $company->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Companie actualizată.']);

        return to_route('companies.index');
    }

    public function destroy(Company $company): RedirectResponse
    {
        $company->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Companie ștearsă.']);

        return to_route('companies.index');
    }
}
