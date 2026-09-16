<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsRemote;
use App\Services\Invoices\SupplierPaymentCheckService;
use App\Services\Omc\OmcReader;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Facturi furnizori (OMC)" check: a payment request from any supplier
 * with invoices in OMC, compared live with what is still open there.
 */
class InvoiceCheckController extends Controller
{
    use ReadsRemote;

    public const SUPPLIER_LIMIT = 30;

    public function index(Request $request, OmcReader $omc): Response
    {
        $company = $omc->company();

        return Inertia::render('payment-checks/invoices', [
            'company' => $company ? ['id' => $company->id, 'name' => $company->name] : null,
            'database' => $omc->label(),
            'filters' => [
                'supplier' => $request->string('supplier')->toString() ?: null,
                'amount' => $request->string('amount')->toString() ?: null,
                'currency' => $request->string('currency')->toString() ?: null,
            ],
        ]);
    }

    /**
     * Suppliers whose name or VAT number contains the term, read live from OMC.
     *
     * @return array{suppliers: list<array<string, mixed>>}
     */
    public function suppliers(Request $request, OmcReader $omc): array
    {
        $term = $request->string('q')->trim()->toString();

        return ['suppliers' => $this->readingOmc(fn () => $omc->searchSuppliers($term, self::SUPPLIER_LIMIT))];
    }

    /**
     * @return array<string, mixed>
     */
    public function check(Request $request, SupplierPaymentCheckService $check): array
    {
        $validated = $request->validate([
            'supplier' => ['required', 'string', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:5'],
        ]);

        $result = $this->readingOmc(fn () => $check->checkLive(
            $validated['supplier'],
            isset($validated['amount']) && $validated['amount'] !== '' ? (float) $validated['amount'] : null,
            $validated['currency'] ?? null,
        ));

        abort_if($result === null, 404, 'Furnizorul nu există în OMC.');

        return $result;
    }

    /**
     * @return array{since: string, suppliers: list<array<string, mixed>>}
     */
    public function open(SupplierPaymentCheckService $check): array
    {
        return $this->readingOmc(fn () => $check->openSuppliersLive());
    }
}
