<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Partner;
use App\Services\Invoices\SupplierPaymentCheckService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Facturi furnizori (OMC)" check: a payment request from any supplier
 * with invoices in the ERP, compared with what is still open there.
 */
class InvoiceCheckController extends Controller
{
    public const SUPPLIER_LIMIT = 30;

    public function index(Request $request, SupplierPaymentCheckService $check): Response
    {
        $companies = Company::query()->orderBy('name')->get(['id', 'name', 'last_synced_at']);
        $requestedCompany = $request->integer('company_id') ?: (int) session('active_company_id');
        $company = $companies->firstWhere('id', $requestedCompany) ?? $companies->first();

        $partner = $company && $request->integer('partner_id')
            ? Partner::query()
                ->where('company_id', $company->id)
                ->furnizori()
                ->find($request->integer('partner_id'), ['id', 'name', 'cui', 'city'])
            : null;

        return Inertia::render('payment-checks/invoices', [
            'companies' => $companies->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'synced_at' => $company->last_synced_at?->toIso8601String(),
            ])->values(),
            'filters' => [
                'company_id' => $company?->id,
                'partner' => $partner ? $this->option($partner) : null,
                'amount' => $request->string('amount')->toString() ?: null,
                'currency' => $request->string('currency')->toString() ?: null,
            ],
            'openSuppliers' => $company ? $check->openSuppliers($company) : [],
        ]);
    }

    /**
     * Supplier partners of a company whose name or VAT number contains the
     * term, alphabetically, for the picker.
     *
     * @return array{suppliers: list<array{id: int, name: string, cui: ?string, city: ?string}>}
     */
    public function suppliers(Request $request, Company $company): array
    {
        $term = $request->string('q')->trim()->toString();

        $suppliers = Partner::query()
            ->where('company_id', $company->id)
            ->furnizori()
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('cui', 'like', "%{$term}%")))
            ->orderBy('name')
            ->limit(self::SUPPLIER_LIMIT)
            ->get(['id', 'name', 'cui', 'city']);

        return ['suppliers' => $suppliers->map(fn (Partner $partner) => $this->option($partner))->values()->all()];
    }

    /**
     * @return array{id: int, name: string, cui: ?string, city: ?string}
     */
    private function option(Partner $partner): array
    {
        return ['id' => $partner->id, 'name' => $partner->name, 'cui' => $partner->cui, 'city' => $partner->city];
    }
}
