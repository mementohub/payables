<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Department;
use App\Models\EInvoice;
use App\Models\Invoice;
use App\Services\EInvoices\EInvoiceXmlParser;
use App\Services\Xlsx\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EInvoiceController extends Controller
{
    public function __construct(private readonly EInvoiceXmlParser $xmlParser) {}

    public function index(Request $request): Response
    {
        $eInvoices = $this->buildListQuery($request)
            ->orderByDesc('msg_data_creare_d')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (EInvoice $row) => $this->transformForList($row));

        $search = $request->string('search')->toString();
        $companyId = $this->resolveActiveCompanyId($request);
        $status = $this->resolveStatus($request);
        $matched = $request->string('matched')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $departmentIds = $this->parseDepartmentIds($request);

        $companies = Company::orderBy('name')->get(['id', 'name']);
        $activeCompany = $companyId
            ? $companies->firstWhere('id', $companyId)
            : null;

        return Inertia::render('e-invoices/index', [
            'eInvoices' => $eInvoices,
            'filters' => [
                'search' => $search ?: null,
                'company_id' => $companyId,
                'status' => $status === '' ? 'all' : $status,
                'matched' => $matched ?: null,
                'from' => $from ?: null,
                'to' => $to ?: null,
                'department_ids' => $departmentIds,
            ],
            'companies' => $companies,
            'activeCompany' => $activeCompany
                ? ['id' => (int) $activeCompany->id, 'name' => $activeCompany->name]
                : null,
            'availableDepartments' => Department::responsabili()
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $ids = $request->input('ids');
        $selectAll = $request->boolean('select_all');

        if (! $selectAll && (! is_array($ids) || empty($ids))) {
            abort(422, 'Selecție goală.');
        }

        $query = $this->buildListQuery($request)->orderByDesc('msg_data_creare_d');

        if (! $selectAll) {
            $idList = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));
            $query->whereIn('e_invoices.id', $idList);
        }

        $headers = ['Data primire', 'Data factură', 'Tip doc XML', 'Număr', 'Furnizor', 'CIF', 'Reg. com.', 'Companie', 'Total', 'TVA', 'Status', 'Asociere', 'Index încărcare', 'Eroare'];

        $rows = function () use ($query) {
            foreach ($query->lazy(500) as $row) {
                yield [
                    $row->msg_data_creare_d?->format('Y-m-d H:i') ?? '',
                    $row->data_doc_xml?->toDateString() ?? '',
                    $row->tip_doc_xml ?? '',
                    $row->nr_doc_xml ?? '',
                    $row->partener_xml ?? '',
                    $row->supplier_cui ?? '',
                    $row->cod_cci_xml ?? '',
                    $row->company->name,
                    $row->total_amount !== null ? (float) $row->total_amount : '',
                    $row->total_vat !== null ? (float) $row->total_vat : '',
                    $this->statusLabel($row),
                    $row->invoice_id ? 'Asociată' : 'Neasociată',
                    $row->msg_index_incarcare ?? '',
                    $row->err_ins_omc ?? '',
                ];
            }
        };

        $filename = 'efacturi-'.now()->format('Ymd-His').'.xlsx';

        return XlsxWriter::streamDownload($filename, $headers, $rows(), 'eFacturi');
    }

    private function buildListQuery(Request $request): Builder
    {
        $search = $request->string('search')->toString();
        $companyId = $this->resolveActiveCompanyId($request);
        $status = $this->resolveStatus($request);
        $matched = $request->string('matched')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $departmentIds = $this->parseDepartmentIds($request);

        return EInvoice::query()
            ->with([
                'company:id,name',
                'partner:id,name,cui',
                'partner.responsabilDepartments:id,name,type',
                'invoice:id,data_doc,tip_doc,nr_doc,val_mon,val_mon_tva,moneda',
            ])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->when($status === 'pending', fn ($q) => $q->whereNull('data_ins_omc'))
            ->when($status === 'error', fn ($q) => $q->whereNotNull('err_ins_omc')->where('err_ins_omc', '!=', ''))
            ->when($status === 'processed', fn ($q) => $q->whereNotNull('data_ins_omc'))
            ->when($matched === 'yes', fn ($q) => $q->whereNotNull('invoice_id'))
            ->when($matched === 'no', fn ($q) => $q->whereNull('invoice_id'))
            ->when($from, fn ($q, $d) => $q->where('msg_data_creare_d', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('msg_data_creare_d', '<=', $d.' 23:59:59'))
            ->when(
                ! empty($departmentIds),
                fn ($q) => $q->whereHas(
                    'partner.responsabilDepartments',
                    fn ($d) => $d->whereIn('departments.id', $departmentIds),
                ),
            )
            ->when($search, function ($q, $term) {
                $q->where(function ($q) use ($term) {
                    $q->where('nr_doc_xml', 'like', "%{$term}%")
                        ->orWhere('partener_xml', 'like', "%{$term}%")
                        ->orWhere('supplier_cui', 'like', "%{$term}%")
                        ->orWhere('cod_cci_xml', 'like', "%{$term}%")
                        ->orWhere('msg_id', 'like', "%{$term}%");
                });
            });
    }

    /**
     * @return list<int>
     */
    private function parseDepartmentIds(Request $request): array
    {
        return collect($request->input('department_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();
    }

    private function resolveStatus(Request $request): string
    {
        $status = $request->string('status')->toString();
        if (! $request->has('status')) {
            return 'pending';
        }

        return $status;
    }

    private function resolveActiveCompanyId(Request $request): ?int
    {
        return $request->exists('company_id')
            ? ($request->integer('company_id') ?: null)
            : ((int) session('active_company_id') ?: null);
    }

    private function statusLabel(EInvoice $row): string
    {
        if ($row->err_ins_omc !== null && $row->err_ins_omc !== '') {
            return 'Cu erori';
        }
        if ($row->data_ins_omc !== null) {
            return 'Procesată';
        }

        return 'Neprocesată';
    }

    public function detail(EInvoice $eInvoice): array
    {
        $eInvoice->load([
            'company:id,name',
            'partner:id,name,cui',
            'partner.responsabilDepartments:id,name,type',
            'invoice:id,data_doc,tip_doc,nr_doc,val_mon,val_mon_tva,moneda',
        ]);

        return [
            'eInvoice' => $this->transformForDetail($eInvoice),
        ];
    }

    public function candidates(EInvoice $eInvoice): array
    {
        $candidates = Invoice::query()
            ->where('company_id', $eInvoice->company_id)
            ->where('tip_doc', 'FactFI')
            ->when($eInvoice->nr_doc_xml, fn ($q, $nr) => $q->where('nr_doc', $nr))
            ->orderByDesc('data_doc')
            ->limit(20)
            ->get(['id', 'data_doc', 'tip_doc', 'nr_doc', 'val_mon', 'moneda'])
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'data_doc' => $invoice->data_doc?->toDateString(),
                'tip_doc' => $invoice->tip_doc,
                'nr_doc' => $invoice->nr_doc,
                'val_mon' => $invoice->val_mon,
                'moneda' => $invoice->moneda,
            ]);

        return ['candidates' => $candidates->all()];
    }

    public function match(Request $request, EInvoice $eInvoice): RedirectResponse
    {
        $invoiceId = $request->input('invoice_id');

        if ($invoiceId === null || $invoiceId === '') {
            $eInvoice->update(['invoice_id' => null]);

            return back();
        }

        $invoice = Invoice::where('id', $invoiceId)
            ->where('company_id', $eInvoice->company_id)
            ->first();

        abort_if($invoice === null, 404);

        $eInvoice->update(['invoice_id' => $invoice->id]);

        return back();
    }

    public function parsed(EInvoice $eInvoice): array
    {
        $payload = $this->xmlParser->parse($eInvoice->msg_xml);

        return ['parsed' => $payload['parsed'], 'error' => $payload['error']];
    }

    private function transformForList(EInvoice $row): array
    {
        $invoiceTotal = $row->invoice ? (float) $row->invoice->val_mon : null;
        $invoiceVat = $row->invoice ? (float) $row->invoice->val_mon_tva : null;
        $eTotal = $row->total_amount !== null ? (float) $row->total_amount : null;
        $eVat = $row->total_vat !== null ? (float) $row->total_vat : null;
        $eCurrency = $row->currency !== null ? strtoupper(trim($row->currency)) : null;
        $invoiceCurrency = $row->invoice && $row->invoice->moneda !== null
            ? strtoupper(trim($row->invoice->moneda))
            : null;

        $totalTolerance = (float) config('einvoices.mismatch_tolerance.total');
        $vatTolerance = (float) config('einvoices.mismatch_tolerance.vat');

        $totalMismatch = $row->invoice && $eTotal !== null && $invoiceTotal !== null
            ? abs($eTotal - $invoiceTotal) > $totalTolerance
            : false;
        $vatMismatch = $row->invoice && $eVat !== null && $invoiceVat !== null
            ? abs($eVat - $invoiceVat) > $vatTolerance
            : false;
        $currencyMismatch = $row->invoice && $eCurrency !== null && $invoiceCurrency !== null
            ? $this->normalizeCurrency($eCurrency) !== $this->normalizeCurrency($invoiceCurrency)
            : false;

        return [
            'id' => $row->id,
            'msg_id' => $row->msg_id,
            'msg_cif' => $row->msg_cif,
            'supplier_cui' => $row->supplier_cui,
            'msg_index_incarcare' => $row->msg_index_incarcare,
            'msg_data_creare_d' => $row->msg_data_creare_d?->format('Y-m-d H:i'),
            'data_doc_xml' => $row->data_doc_xml?->toDateString(),
            'tip_doc_xml' => $row->tip_doc_xml,
            'nr_doc_xml' => $row->nr_doc_xml,
            'partener_xml' => $row->partener_xml,
            'cod_cci_xml' => $row->cod_cci_xml,
            'total_amount' => $eTotal,
            'total_vat' => $eVat,
            'currency' => $eCurrency,
            'data_ins_omc' => $row->data_ins_omc?->format('Y-m-d H:i'),
            'err_ins_omc' => $row->err_ins_omc,
            'status' => $row->status,
            'company' => ['id' => $row->company->id, 'name' => $row->company->name],
            'partner' => $row->partner ? [
                'id' => $row->partner->id,
                'name' => $row->partner->name,
                'cui' => $row->partner->cui,
            ] : null,
            'responsabil_departments' => $row->partner
                ? $row->partner->responsabilDepartments->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                ])->values()
                : [],
            'invoice' => $row->invoice ? [
                'id' => $row->invoice->id,
                'data_doc' => $row->invoice->data_doc?->toDateString(),
                'tip_doc' => $row->invoice->tip_doc,
                'nr_doc' => $row->invoice->nr_doc,
                'val_mon' => $invoiceTotal,
                'val_mon_tva' => $invoiceVat,
                'moneda' => $row->invoice->moneda,
            ] : null,
            'mismatch' => [
                'total' => $totalMismatch,
                'vat' => $vatMismatch,
                'currency' => $currencyMismatch,
                'any' => $totalMismatch || $vatMismatch || $currencyMismatch,
            ],
        ];
    }

    private function transformForDetail(EInvoice $row): array
    {
        return [
            ...$this->transformForList($row),
            'msg_detalii' => $row->msg_detalii,
            'msg_xml' => $row->msg_xml,
        ];
    }

    /**
     * Normalize currency aliases used across the system. The local ERP stores
     * the Romanian leu as "Lei" while UBL e-invoices use the ISO code "RON";
     * treat them (and a few other common aliases) as equivalent for mismatch
     * checks.
     */
    private function normalizeCurrency(string $currency): string
    {
        return match ($currency) {
            'LEI' => 'RON',
            default => $currency,
        };
    }
}
