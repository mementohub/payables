<?php

namespace App\Http\Controllers;

use App\Models\CompanyBankAccount;
use App\Models\Invoice;
use App\Services\Invoices\InvoiceListQuery;
use App\Services\PaymentExport\BtPaymentRow;
use App\Services\Xlsx\XlsxWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentExportController extends Controller
{
    private const BT_MAX_ROWS = 500;

    /**
     * Build the prefilled BT payment payload for a set of invoices.
     */
    public function btPrepare(Request $request): JsonResponse
    {
        $request->validate([
            'invoice_ids' => ['nullable', 'array'],
            'invoice_ids.*' => ['integer', 'min:1'],
            'select_all' => ['nullable', 'boolean'],
        ]);

        $selectAll = $request->boolean('select_all');
        $explicitIds = array_values(array_filter(
            array_map('intval', (array) $request->input('invoice_ids', [])),
            fn ($id) => $id > 0,
        ));

        if (! $selectAll && empty($explicitIds)) {
            abort(422, 'Selecție goală.');
        }

        $query = $selectAll
            ? InvoiceListQuery::build($request, 'primite')
            : Invoice::query();

        $query
            ->where('is_fully_approved', true)
            ->with(['partner.bankAccounts', 'company:id,name,cui'])
            ->orderBy('invoices.data_doc');

        if (! $selectAll) {
            $query->whereIn('invoices.id', $explicitIds);
        }

        $eligibleCount = (clone $query)->count();
        $invoices = $query->limit(self::BT_MAX_ROWS)->get();

        $totalRequested = $selectAll ? $eligibleCount : count($explicitIds);
        $skipped = max(0, $totalRequested - $invoices->count());
        $truncated = $eligibleCount > self::BT_MAX_ROWS;

        if ($invoices->isEmpty()) {
            return response()->json([
                'rows' => [],
                'company_accounts' => [],
                'invoices_skipped' => $skipped,
                'truncated' => false,
                'limit' => self::BT_MAX_ROWS,
            ]);
        }

        $companyId = (int) $invoices->first()->company_id;

        $rows = $invoices->values()->map(
            fn (Invoice $invoice, int $idx) => BtPaymentRow::build($invoice, $idx + 1)
        );

        $companyAccounts = CompanyBankAccount::query()
            ->where('company_id', $companyId)
            ->where('is_discontinued', false)
            ->orderByDesc('is_default')
            ->orderBy('currency')
            ->orderBy('bank')
            ->get(['id', 'bank', 'iban', 'currency', 'bic', 'swift', 'is_default'])
            ->map(fn (CompanyBankAccount $account) => [
                'id' => $account->id,
                'bank' => $account->bank,
                'iban' => $account->iban,
                'currency' => $account->currency,
                'bic' => $account->bic,
                'swift' => $account->swift,
                'is_default' => (bool) $account->is_default,
            ]);

        return response()->json([
            'company' => [
                'id' => $invoices->first()->company->id,
                'name' => $invoices->first()->company->name,
                'cui' => $invoices->first()->company->cui,
            ],
            'rows' => $rows,
            'company_accounts' => $companyAccounts,
            'invoices_skipped' => $skipped,
            'truncated' => $truncated,
            'limit' => self::BT_MAX_ROWS,
        ]);
    }

    /**
     * Generate the BT-formatted xlsx from the user-confirmed payload.
     */
    public function btDownload(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'source_account' => ['required', 'string', 'max:40'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.beneficiary_name' => ['required', 'string', 'max:255'],
            'rows.*.target_account_number' => ['required', 'string', 'max:40'],
            'rows.*.beneficiary_bank_bic' => ['nullable', 'string', 'max:20'],
            'rows.*.beneficiary_fiscal_code' => ['nullable', 'string', 'max:20'],
            'rows.*.amount' => ['required', 'numeric', 'min:0.01'],
            'rows.*.payment_ref_1' => ['nullable', 'string', 'max:100'],
            'rows.*.payment_ref_2' => ['nullable', 'string', 'max:100'],
            'rows.*.value_date' => ['required', 'date'],
            'rows.*.urgent' => ['required', 'in:F,T'],
        ]);

        $headers = [
            'OrderNumber', 'SourceAccountNumber', 'TargetAccountNumber',
            'BeneficiaryName', 'BeneficiaryBankBIC', 'BeneficiaryFiscalCode',
            'Amount', 'PaymentRef1', 'PaymentRef2', 'ValueDate', 'Urgent',
        ];

        $sourceAccount = $validated['source_account'];
        $rowsData = $validated['rows'];

        $rows = function () use ($sourceAccount, $rowsData) {
            foreach ($rowsData as $i => $row) {
                yield [
                    $i + 1,
                    $sourceAccount,
                    $row['target_account_number'],
                    $row['beneficiary_name'],
                    $row['beneficiary_bank_bic'] ?? '',
                    $row['beneficiary_fiscal_code'] ?? '',
                    (float) $row['amount'],
                    $row['payment_ref_1'] ?? '',
                    $row['payment_ref_2'] ?? '',
                    $row['value_date'],
                    $row['urgent'],
                ];
            }
        };

        $filename = 'plata-bt-'.now()->format('Ymd-His').'.xlsx';

        return XlsxWriter::streamDownload($filename, $headers, $rows(), 'BT Payments');
    }
}
