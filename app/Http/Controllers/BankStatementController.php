<?php

namespace App\Http\Controllers;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankStatementController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->exists('company_id')
            ? ($request->integer('company_id') ?: null)
            : null;

        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $onlyUnallocated = $request->boolean('only_unallocated');
        // De la cine am încasat sau cui i-am plătit: numele partenerului, așa
        // cum apare pe operațiune.
        $partner = trim($request->string('partner')->toString());

        $statements = BankStatement::query()
            ->with('company:id,name')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->when($from, fn ($q, $d) => $q->where('data_extras', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('data_extras', '<=', $d))
            ->when($onlyUnallocated, fn ($q) => $q->where('unallocated_count', '>', 0))
            ->when($partner !== '', fn ($q) => $q->whereHas('lines', fn ($lines) => $lines->forPartner($partner)))
            ->orderByDesc('data_extras')
            ->orderBy('company_id')
            ->orderBy('iban')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (BankStatement $s) => [
                'id' => $s->id,
                'data_extras' => $s->data_extras?->toDateString(),
                'banca' => $s->banca,
                'iban' => $s->iban,
                'operator' => $s->operator,
                'moneda' => $s->moneda,
                'lines_count' => $s->lines_count,
                'unallocated_count' => $s->unallocated_count,
                'total_incoming' => (float) $s->total_incoming,
                'total_outgoing' => (float) $s->total_outgoing,
                'total_unallocated' => (float) $s->total_unallocated,
                'company' => ['id' => $s->company->id, 'name' => $s->company->name],
            ]);

        $companies = Company::orderBy('name')->get(['id', 'name']);
        $activeCompany = $companyId
            ? $companies->firstWhere('id', $companyId)
            : null;

        return Inertia::render('bank-statements/index', [
            'statements' => $statements,
            'filters' => [
                'company_id' => $companyId ?: null,
                'from' => $from ?: null,
                'to' => $to ?: null,
                'only_unallocated' => $onlyUnallocated,
                'partner' => $partner ?: null,
            ],
            'companies' => $companies,
            'activeCompany' => $activeCompany
                ? ['id' => (int) $activeCompany->id, 'name' => $activeCompany->name]
                : null,
        ]);
    }

    /**
     * Tranzacțiile din toate extrasele, nu dintr-unul singur.
     *
     * Întrebarea obișnuită a Trezoreriei — „ce i-am plătit furnizorului ăsta
     * și din ce bancă” — trece peste extrase: un furnizor e plătit din mai
     * multe conturi, în luni diferite. Aici se caută după el, cu totalurile
     * strânse pe bancă.
     */
    public function transactions(Request $request): Response
    {
        $partner = trim($request->string('partner')->toString());
        $direction = $request->string('direction')->toString();
        $direction = in_array($direction, ['incoming', 'outgoing'], true) ? $direction : null;
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $companyId = $request->integer('company_id') ?: null;
        // Băncile alese; una singură venită ca text merge la fel, pentru
        // linkurile vechi.
        $banks = collect($request->input('banca', []))
            ->flatten()
            ->map(fn ($name) => self::bankName((string) $name))
            ->filter()
            ->unique()
            ->values();

        // Numele e scris în extrase cum apucă: cu majuscule sau nu, umplut cu
        // spații care nu se rup. Filtrul caută valorile brute care, curățate,
        // dau banca aleasă.
        $raw = $banks->isEmpty() ? collect() : BankStatement::query()
            ->whereNotNull('banca')
            ->distinct()
            ->pluck('banca')
            ->filter(fn ($name) => $banks->contains(self::bankName((string) $name)))
            ->values();

        // Extrasele au peste un milion de linii: fără nicio căutare, pagina ar
        // aduna toată istoria la fiecare deschidere. Când nu se caută nimic
        // anume, se uită la ultimele trei luni — și o spune în filtru, ca omul
        // să poată lărgi.
        if ($from === '' && $to === '' && $partner === '' && $banks->isEmpty()) {
            $from = now()->subMonths(3)->startOfMonth()->toDateString();
        }

        $lines = fn () => BankStatementLine::query()
            ->whereHas('statement', fn ($statement) => $statement
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->when($from !== '', fn ($q) => $q->where('data_extras', '>=', $from))
                ->when($to !== '', fn ($q) => $q->where('data_extras', '<=', $to))
                ->when($raw->isNotEmpty(), fn ($q) => $q->whereIn('banca', $raw->all())))
            ->when($partner !== '', fn ($q) => $q->forPartner($partner))
            ->when($direction !== null, fn ($q) => $q->where('direction', $direction));

        $rows = $lines()
            ->with([
                'statement:id,company_id,data_extras,banca,iban',
                'statement.company:id,name',
                'partner:id,name',
                'allocations:id,bank_statement_line_id,tip_doc_com,nr_doc_com,invoice_id,val_fin',
                'allocations.invoice:id,nr_doc,tip_doc',
            ])
            ->orderByDesc('data_doc')
            ->orderByDesc('id')
            ->paginate(100)
            ->withQueryString()
            ->through(fn (BankStatementLine $line) => [
                'id' => $line->id,
                'data_doc' => $line->data_doc?->toDateString(),
                'tip_doc' => $line->tip_doc,
                'nr_doc' => $line->nr_doc,
                'direction' => $line->direction,
                'moneda' => $line->moneda,
                'val_mon' => (float) $line->val_mon,
                'unallocated' => $line->unallocated,
                'partner' => $line->partner?->name ?? $line->partener_name,
                'counterparty' => $line->direction === 'incoming' ? $line->cine_preda : $line->cine_primeste,
                'obs_txt' => $line->obs_txt,
                'statement' => $line->statement ? [
                    'id' => $line->statement->id,
                    'data_extras' => $line->statement->data_extras?->toDateString(),
                    'banca' => $line->statement->banca,
                    'iban' => $line->statement->iban,
                    'company' => $line->statement->company?->name,
                ] : null,
                'invoices' => $line->allocations
                    ->map(fn ($allocation) => [
                        'id' => $allocation->invoice?->id,
                        'nr_doc' => $allocation->invoice?->nr_doc ?? $allocation->nr_doc_com,
                        'tip_doc' => $allocation->invoice?->tip_doc ?? $allocation->tip_doc_com,
                        'val_fin' => (float) $allocation->val_fin,
                    ])
                    ->values()
                    ->all(),
            ]);

        // Cât s-a plătit și cât s-a încasat, strâns întâi pe bancă. Conturile
        // se desfac abia când e aleasă o bancă: un furnizor mare apare pe zeci
        // de IBAN-uri și monede, iar un card pentru fiecare combinație nu mai
        // e un sumar.
        // (`lines` e cuvânt rezervat în MySQL, de aici aliasul.)
        $sums = $lines()
            ->join('bank_statements as s', 's.id', '=', 'bank_statement_lines.bank_statement_id')
            ->selectRaw('s.banca, s.iban, bank_statement_lines.moneda, bank_statement_lines.direction, count(*) as lines_count, sum(bank_statement_lines.val_mon) as total')
            ->groupBy('s.banca', 's.iban', 'bank_statement_lines.moneda', 'bank_statement_lines.direction')
            ->get();

        $fold = fn (iterable $rows) => collect($rows)
            ->map(fn ($row) => [
                'moneda' => $row->moneda,
                'direction' => $row->direction,
                'lines' => (int) $row->lines_count,
                'total' => round((float) $row->total, 2),
            ])
            ->sortBy([['direction', 'asc'], ['moneda', 'asc']])
            ->values()
            ->all();

        // Aceeași bancă e scrisă când cu majuscule, când nu: se strâng la un
        // loc, cu ortografia cea mai des întâlnită.
        $byBank = $sums
            ->groupBy(fn ($row) => self::bankName((string) $row->banca))
            ->map(fn ($rows, $bank) => [
                'banca' => $bank !== '' ? $bank : null,
                'accounts' => $rows->pluck('iban')->unique()->count(),
                'lines' => (int) $rows->sum('lines_count'),
                'totals' => $fold($rows),
            ])
            ->sortByDesc('lines')
            ->values()
            ->all();

        return Inertia::render('bank-statements/transactions', [
            'lines' => $rows,
            // Totalul a tot ce s-a filtrat, pe monedă și pe sens.
            'totals' => [
                'lines' => (int) $sums->sum('lines_count'),
                'by_currency' => $fold($sums->groupBy(fn ($row) => $row->moneda.'|'.$row->direction)->map(fn ($rows) => (object) [
                    'moneda' => $rows->first()->moneda,
                    'direction' => $rows->first()->direction,
                    'lines_count' => $rows->sum('lines_count'),
                    'total' => $rows->sum('total'),
                ])),
            ],
            'by_bank' => $byBank,
            'filters' => [
                'partner' => $partner ?: null,
                'direction' => $direction,
                'from' => $from ?: null,
                'to' => $to ?: null,
                'company_id' => $companyId,
                'banca' => $banks->all(),
            ],
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            // În filtru merg băncile, nu conturile: sunt sute de IBAN-uri, iar
            // omul întreabă „din ce bancă”, nu „din ce cont”.
            'banks' => BankStatement::query()
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->whereNotNull('banca')
                ->distinct()
                ->pluck('banca')
                ->map(fn ($name) => self::bankName((string) $name))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ]);
    }

    /**
     * Numele băncii, curățat.
     *
     * În extrase apare cum apucă: cu majuscule sau nu, umplut la coadă cu
     * spații care nu se rup (U+00A0), așa că aceeași bancă ieșea de două ori
     * în filtru și cu sumele rupte în sumar.
     */
    private static function bankName(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $name)) ?? ''));
    }

    public function show(Request $request, BankStatement $bankStatement): Response
    {
        $bankStatement->load('company:id,name');

        $onlyUnallocated = $request->boolean('only_unallocated');
        $direction = $request->string('direction')->toString();
        $partner = trim($request->string('partner')->toString());

        $lines = $bankStatement->lines()
            ->with([
                'partner:id,name',
                'allocations' => fn ($q) => $q->orderBy('data_doc_com')->orderBy('nr_doc_com'),
                'allocations.invoice:id,nr_doc,tip_doc,data_doc,partner_id,val_mon,val_mon_tva,val_mon_paid,moneda',
                'allocations.invoice.partner:id,name',
            ])
            ->when($direction === 'incoming' || $direction === 'outgoing', fn ($q) => $q->where('direction', $direction))
            ->when($partner !== '', fn ($q) => $q->forPartner($partner))
            ->orderBy('data_doc')
            ->orderBy('tip_doc')
            ->orderBy('nr_doc')
            ->get();

        $mappedLines = $lines
            ->map(fn ($line) => [
                'id' => $line->id,
                'data_doc' => $line->data_doc?->toDateString(),
                'tip_doc' => $line->tip_doc,
                'nr_doc' => $line->nr_doc,
                'direction' => $line->direction,
                'partener_name' => $line->partener_name,
                'partner' => $line->partner ? ['id' => $line->partner->id, 'name' => $line->partner->name] : null,
                'emitent' => $line->emitent,
                'cine_preda' => $line->cine_preda,
                'cine_primeste' => $line->cine_primeste,
                'obs_txt' => $line->obs_txt,
                'moneda' => $line->moneda,
                'val_mon' => (float) $line->val_mon,
                'val_allocated' => (float) $line->val_allocated,
                'unallocated' => (float) $line->unallocated,
                'is_unallocated' => $line->is_unallocated,
                'allocations' => $line->allocations->map(fn ($alloc) => [
                    'id' => $alloc->id,
                    'data_doc_com' => $alloc->data_doc_com?->toDateString(),
                    'tip_doc_com' => $alloc->tip_doc_com,
                    'nr_doc_com' => $alloc->nr_doc_com,
                    'val_fin' => (float) $alloc->val_fin,
                    'val_com' => (float) $alloc->val_com,
                    'invoice' => $alloc->invoice ? [
                        'id' => $alloc->invoice->id,
                        'nr_doc' => $alloc->invoice->nr_doc,
                        'tip_doc' => $alloc->invoice->tip_doc,
                        'data_doc' => $alloc->invoice->data_doc?->toDateString(),
                        'moneda' => $alloc->invoice->moneda,
                        'val_mon' => (float) $alloc->invoice->val_mon,
                        'val_mon_tva' => (float) $alloc->invoice->val_mon_tva,
                        'val_mon_paid' => (float) $alloc->invoice->val_mon_paid,
                        'partner' => $alloc->invoice->partner ? [
                            'id' => $alloc->invoice->partner->id,
                            'name' => $alloc->invoice->partner->name,
                        ] : null,
                    ] : null,
                ])->values(),
            ])
            ->when($onlyUnallocated, fn ($c) => $c->filter(fn ($l) => $l['is_unallocated']))
            ->values();

        return Inertia::render('bank-statements/show', [
            'statement' => [
                'id' => $bankStatement->id,
                'data_extras' => $bankStatement->data_extras?->toDateString(),
                'banca' => $bankStatement->banca,
                'iban' => $bankStatement->iban,
                'operator' => $bankStatement->operator,
                'moneda' => $bankStatement->moneda,
                'lines_count' => $bankStatement->lines_count,
                'unallocated_count' => $bankStatement->unallocated_count,
                'total_incoming' => (float) $bankStatement->total_incoming,
                'total_outgoing' => (float) $bankStatement->total_outgoing,
                'total_unallocated' => (float) $bankStatement->total_unallocated,
                'company' => ['id' => $bankStatement->company->id, 'name' => $bankStatement->company->name],
            ],
            'lines' => $mappedLines,
            'filters' => [
                'only_unallocated' => $onlyUnallocated,
                'direction' => $direction ?: null,
                'partner' => $partner ?: null,
            ],
            // Cât face ce s-a filtrat: altfel omul care caută un furnizor
            // vede liniile, dar trebuie să le adune singur.
            'shown' => [
                'lines' => $mappedLines->count(),
                'incoming' => round($mappedLines->where('direction', 'incoming')->sum('val_mon'), 2),
                'outgoing' => round($mappedLines->where('direction', 'outgoing')->sum('val_mon'), 2),
            ],
            'activeCompany' => ['id' => (int) $bankStatement->company->id, 'name' => $bankStatement->company->name],
        ]);
    }
}
