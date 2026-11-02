<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsRemote;
use App\Models\EtripSupplier;
use App\Services\Etrip\CheckinCostCheckService;
use App\Services\Etrip\EtripReader;
use App\Services\Etrip\EtripSupplierSyncService;
use App\Services\Etrip\SupplierReconciliationService;
use App\Services\Invoices\SupplierPaymentCheckService;
use App\Services\Omc\OmcReader;
use App\Services\Xlsx\XlsxWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Verificare plăți → Check-in (eTrip): the supplier cost of the services
 * booked in any eTrip base, compared with a payment request, and the
 * supplier's eTrip invoices against the services it delivered.
 */
class PaymentCheckController extends Controller
{
    use ReadsRemote;

    public const EXPECTED_WINDOWS = [2, 7, 14];

    public function index(Request $request, OmcReader $omc): Response
    {
        $bases = $this->bases();
        $requested = $request->string('connection')->toString();

        return Inertia::render('payment-checks/index', [
            'bases' => $bases,
            'company_id' => $omc->company()?->id,
            'categories' => CheckinCostCheckService::CATEGORY_LABELS,
            'windows' => self::EXPECTED_WINDOWS,
            'filters' => [
                'view' => $request->string('view')->toString() === 'invoices' ? 'invoices' : 'checkin',
                'connection' => array_key_exists($requested, (array) config('etrip.connections')) ? $requested : null,
                'supplier' => $request->string('supplier')->toString() ?: null,
                'from' => $request->string('from')->toString() ?: Carbon::today()->toDateString(),
                'to' => $request->string('to')->toString() ?: Carbon::tomorrow()->toDateString(),
                'category' => in_array($request->string('category')->toString(), CheckinCostCheckService::CATEGORIES, true)
                    ? $request->string('category')->toString()
                    : 'hotel',
                'amount' => $request->string('amount')->toString() ?: null,
                'currency' => $request->string('currency')->toString() ?: null,
                'period_from' => $request->date('period_from')?->toDateString(),
                'period_to' => $request->date('period_to')?->toDateString(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function check(Request $request, CheckinCostCheckService $check, EtripSupplierSyncService $sync): array
    {
        $validated = $request->validate([
            'connection' => ['required', Rule::in(array_keys((array) config('etrip.connections')))],
            'supplier' => ['required', 'string', 'max:50'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'category' => ['nullable', Rule::in(CheckinCostCheckService::CATEGORIES)],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:5'],
        ]);

        $connection = $validated['connection'];

        $supplier = EtripSupplier::query()
            ->forConnection($connection)
            ->where('code', $validated['supplier'])
            ->first()
            ?? $this->readingEtrip(fn () => $sync->remember($connection, $validated['supplier']));

        abort_if($supplier === null, 404, 'Furnizorul nu există în eTrip.');

        return $this->readingEtrip(fn () => $check->check(
            $connection,
            $supplier,
            Carbon::parse($validated['from'])->startOfDay(),
            Carbon::parse($validated['to'])->startOfDay(),
            $validated['category'] ?? 'hotel',
            isset($validated['amount']) && $validated['amount'] !== '' ? (float) $validated['amount'] : null,
            $validated['currency'] ?? null,
        ));
    }

    /**
     * The supplier's eTrip invoices dated in the range against the services
     * it delivered with check-in in the range, read live.
     *
     * @return array<string, mixed>
     */
    public function reconcile(Request $request, SupplierReconciliationService $reconciliation, EtripSupplierSyncService $sync): array
    {
        $validated = $request->validate([
            'connection' => ['required', Rule::in(array_keys((array) config('etrip.connections')))],
            'supplier' => ['required', 'string', 'max:50'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $connection = $validated['connection'];

        $supplier = EtripSupplier::query()
            ->forConnection($connection)
            ->where('code', $validated['supplier'])
            ->first()
            ?? $this->readingEtrip(fn () => $sync->remember($connection, $validated['supplier']));

        abort_if($supplier === null, 404, 'Furnizorul nu există în eTrip.');

        return $this->readingEtrip(fn () => $reconciliation->reconcile(
            $connection,
            $supplier,
            Carbon::parse($validated['from'])->startOfDay(),
            Carbon::parse($validated['to'])->startOfDay(),
        ));
    }

    /**
     * O factură desfăcută: liniile ei și serviciul din spatele fiecăreia.
     *
     * Tabelul arată factura ca o singură cifră, iar întrebarea care vine
     * după e mereu aceeași: ce dosare, ce servicii, ce check-in. Se citește
     * la cerere, când omul deschide rândul, nu pentru toate cele o sută de
     * facturi deodată.
     *
     * @return array<string, mixed>
     */
    public function invoiceLines(Request $request, EtripReader $reader, EtripSupplierSyncService $sync): array
    {
        $validated = $request->validate([
            'connection' => ['required', Rule::in(array_keys((array) config('etrip.connections')))],
            'supplier' => ['required', 'string', 'max:50'],
            'invoice' => ['required', 'integer', 'min:1'],
        ]);

        $connection = $validated['connection'];

        $supplier = EtripSupplier::query()
            ->forConnection($connection)
            ->where('code', $validated['supplier'])
            ->first()
            ?? $this->readingEtrip(fn () => $sync->remember($connection, $validated['supplier']));

        abort_if($supplier === null, 404, 'Furnizorul nu există în eTrip.');

        return [
            'lines' => $this->readingEtrip(fn () => $reader->supplierInvoiceLines(
                $connection,
                $supplier->code,
                (int) $validated['invoice'],
            )),
        ];
    }

    /**
     * Toate serviciile din verificare, într-o foaie de calcul.
     *
     * Ecranul duce primele șase sute de rânduri, cât încap fără să
     * îngreuneze pagina; cine are de lucru cu ele — de bifat, de adunat, de
     * pus lângă cererea furnizorului — le ia pe toate de aici.
     */
    public function exportCheck(Request $request, CheckinCostCheckService $check, EtripSupplierSyncService $sync): StreamedResponse
    {
        $validated = $request->validate([
            'connection' => ['required', Rule::in(array_keys((array) config('etrip.connections')))],
            'supplier' => ['required', 'string', 'max:50'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'category' => ['nullable', Rule::in(CheckinCostCheckService::CATEGORIES)],
        ]);

        $connection = $validated['connection'];

        $supplier = EtripSupplier::query()
            ->forConnection($connection)
            ->where('code', $validated['supplier'])
            ->first()
            ?? $this->readingEtrip(fn () => $sync->remember($connection, $validated['supplier']));

        abort_if($supplier === null, 404, 'Furnizorul nu există în eTrip.');

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->startOfDay();

        $lines = $this->readingEtrip(fn () => $check->lines(
            $connection,
            $supplier,
            $from,
            $to,
            $validated['category'] ?? 'hotel',
        ));

        $summaries = $check->summaries($lines);
        $totals = $lines->groupBy('currency')->map(fn ($group, $currency) => [
            'currency' => (string) $currency,
            'cost' => round((float) $group->sum('cost'), 2),
            'items' => $group->count(),
            'bookings' => $group->pluck('booking')->unique()->count(),
            'pax' => (int) $group->sum('pax'),
        ])->sortBy('currency')->values();

        $detail = function () use ($lines) {
            foreach ($lines as $line) {
                yield [
                    (int) $line['booking'],
                    (int) $line['item'],
                    (string) ($line['lead'] ?? ''),
                    (string) $line['product_label'],
                    (string) $line['category'],
                    (string) $line['start_date'],
                    $line['nights'] !== null ? (int) $line['nights'] : '',
                    (string) ($line['hotel'] ?? ''),
                    (string) ($line['room'] ?? ''),
                    (string) ($line['meal'] ?? ''),
                    (string) ($line['transfer'] ?? ''),
                    (string) $line['service'],
                    (int) $line['pax'],
                    (string) $line['currency'],
                    (float) $line['cost'],
                ];
            }
        };

        $money = $totals->count() === 1 ? ' ('.$totals->first()['currency'].')' : '';

        $sheets = [
            [
                'name' => 'Sinteză',
                'headers' => ['Indicator', 'Valoare'],
                'widths' => [34, 46],
                'rows' => [
                    ['Furnizor', $supplier->name],
                    ['Cod furnizor', $supplier->code],
                    ['Bază eTrip', (string) config('etrip.connections.'.$connection, $connection)],
                    ['Check-in între', $from->format('d.m.Y').' și '.$to->format('d.m.Y')],
                    ['Categorie', match ($validated['category'] ?? 'hotel') {
                        'hotel' => 'Cazare',
                        'transfer' => 'Transferuri',
                        'all' => 'Toate serviciile',
                        default => (string) ($validated['category'] ?? 'hotel'),
                    }],
                    ['Servicii', $lines->count()],
                    ['Dosare', $lines->pluck('booking')->unique()->count()],
                    ['Pax', (int) $lines->sum('pax')],
                    ['Extras la', now()->format('d.m.Y H:i')],
                    [null, null],
                    ['Cost pe monedă', null],
                    ...$totals->map(fn (array $row) => [$row['currency'], (float) $row['cost']])->all(),
                ],
            ],
            [
                'name' => 'Pe hotel',
                'headers' => ['Hotel / serviciu', 'Monedă', 'Cost', 'Dosare', 'Servicii', 'Pax'],
                'widths' => [46, 9, 14, 9, 9, 8],
                'rows' => array_map(fn (array $row) => [
                    (string) $row['name'], (string) $row['currency'], (float) $row['cost'],
                    (int) $row['bookings'], (int) $row['items'], (int) $row['pax'],
                ], $summaries['by_hotel']),
                'chart' => [
                    'title' => 'Cost pe hotel / serviciu'.$money,
                    'kind' => 'bar', 'categories' => 0, 'values' => 2, 'limit' => 20,
                    'anchor' => ['col' => 7, 'row' => 1, 'cols' => 10, 'rows' => 26],
                ],
            ],
            [
                'name' => 'Pe zi de check-in',
                'headers' => ['Check-in', 'Monedă', 'Cost', 'Dosare', 'Servicii', 'Pax'],
                'widths' => [14, 9, 14, 9, 9, 8],
                'rows' => array_map(fn (array $row) => [
                    (string) $row['date'], (string) $row['currency'], (float) $row['cost'],
                    (int) $row['bookings'], (int) $row['items'], (int) $row['pax'],
                ], $summaries['by_day']),
                'chart' => [
                    'title' => 'Cost pe zi de check-in'.$money,
                    'kind' => 'col', 'categories' => 0, 'values' => 2, 'limit' => 62,
                    'anchor' => ['col' => 7, 'row' => 1, 'cols' => 12, 'rows' => 24],
                ],
            ],
            [
                'name' => 'Pe tip de produs',
                'headers' => ['Tip de produs', 'Cod', 'Monedă', 'Cost', 'Dosare', 'Servicii', 'Pax'],
                'widths' => [36, 8, 9, 14, 9, 9, 8],
                'rows' => array_map(fn (array $row) => [
                    (string) $row['label'], (int) $row['product_type'], (string) $row['currency'], (float) $row['cost'],
                    (int) $row['bookings'], (int) $row['items'], (int) $row['pax'],
                ], $summaries['by_product']),
                'chart' => [
                    'title' => 'Cost pe tip de produs'.$money,
                    'kind' => 'bar', 'categories' => 0, 'values' => 3, 'limit' => 20,
                    'anchor' => ['col' => 8, 'row' => 1, 'cols' => 10, 'rows' => 22],
                ],
            ],
            [
                'name' => 'Check-in',
                'headers' => ['Dosar', 'Poziție', 'Titular', 'Tip serviciu', 'Categorie', 'Check-in', 'Nopți', 'Hotel', 'Cameră', 'Masă', 'Transfer', 'Serviciu', 'Pax', 'Monedă', 'Cost'],
                'widths' => [10, 9, 26, 18, 11, 12, 7, 30, 16, 12, 18, 34, 7, 9, 14],
                'rows' => $detail(),
            ],
        ];

        return XlsxWorkbook::streamDownload(
            sprintf('check-in-%s-%s-%s.xlsx', Str::slug($supplier->name), $from->format('Ymd'), $to->format('Ymd')),
            $sheets,
        );
    }

    /**
     * Toată verificarea „facturi vs. servicii”, într-o foaie de calcul.
     *
     * Ecranul e făcut pentru citit: spune unde e problema și cât e de mare.
     * Foaia e făcută pentru lucrat — de trimis furnizorului, de pus lângă
     * extrasul lui, de bifat linie cu linie — așa că duce tot ce stă în
     * spatele ecranului, inclusiv liniile fiecărei facturi.
     */
    public function exportReconcile(Request $request, SupplierReconciliationService $reconciliation, EtripReader $reader, EtripSupplierSyncService $sync): StreamedResponse
    {
        $validated = $request->validate([
            'connection' => ['required', Rule::in(array_keys((array) config('etrip.connections')))],
            'supplier' => ['required', 'string', 'max:50'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $connection = $validated['connection'];

        $supplier = EtripSupplier::query()
            ->forConnection($connection)
            ->where('code', $validated['supplier'])
            ->first()
            ?? $this->readingEtrip(fn () => $sync->remember($connection, $validated['supplier']));

        abort_if($supplier === null, 404, 'Furnizorul nu există în eTrip.');

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->startOfDay();

        $result = $this->readingEtrip(fn () => $reconciliation->reconcile($connection, $supplier, $from, $to));

        // Liniile fiecărei facturi, citite una câte una: aceeași desfacere
        // pe care o vede omul când deschide un rând pe ecran.
        $byInvoice = $this->readingEtrip(fn () => $reader->supplierInvoiceLinesFor($connection, $supplier->code, $from, $to));

        $lines = function () use ($byInvoice, $result) {
            foreach ($result['invoices'] as $invoice) {
                foreach (($byInvoice[(int) $invoice['id']] ?? []) as $line) {
                    yield [
                        (string) $invoice['number'],
                        (string) $invoice['date'],
                        $line['booking'] !== null ? (int) $line['booking'] : '',
                        (string) ($line['conf_no'] ?? ''),
                        (string) ($line['short_description'] ?? $line['description'] ?? ''),
                        (string) ($line['product_type'] ?? ''),
                        (string) ($line['checkin'] ?? ''),
                        (string) ($line['checkout'] ?? ''),
                        (string) ($line['supplier_status'] ?? ''),
                        (float) $line['quantity'],
                        (float) $line['amount'],
                        (float) $line['line_total'],
                        (string) ($line['vat_label'] ?? ''),
                        $line['vat_amount'] !== null ? (float) $line['vat_amount'] : '',
                        (string) $invoice['currency'],
                        $line['etrip_cost'] !== null ? (float) $line['etrip_cost'] : '',
                    ];
                }
            }
        };

        $sheets = [
            [
                'name' => 'Sinteză',
                'headers' => ['Indicator', 'Valoare'],
                'widths' => [34, 46],
                'rows' => [
                    ['Furnizor', $supplier->name],
                    ['Cod furnizor', $supplier->code],
                    ['Bază eTrip', (string) config('etrip.connections.'.$connection, $connection)],
                    ['Perioada', $from->format('d.m.Y').' – '.$to->format('d.m.Y')],
                    ['Facturi în eTrip', (int) $result['invoices_total']],
                    ['Plăți citite din', $result['payments_source'] === 'omc' ? 'OMC' : 'eTrip'],
                    ['Extras la', now()->format('d.m.Y H:i')],
                    [null, null],
                    ['Pe monedă', null],
                    ['Monedă', 'Cost servicii'],
                    ...array_map(fn (array $kpi) => [
                        (string) $kpi['currency'], (float) $kpi['cost'],
                    ], $result['kpis']),
                ],
            ],
            [
                'name' => 'Facturi',
                'headers' => ['Factura', 'Data', 'Scadență', 'Sursa scadenței', 'Zile întârziere', 'Monedă', 'Valoare', 'Plătit', 'Rest', 'Finalizată', 'Bună de plată', 'Corecție', 'Check-in de la', 'până la', 'Dosare', 'Linii', 'Cost eTrip servicii'],
                'widths' => [18, 12, 12, 14, 10, 8, 14, 14, 14, 11, 13, 10, 13, 13, 8, 8, 18],
                'rows' => array_map(fn (array $invoice) => [
                    (string) $invoice['number'],
                    (string) $invoice['date'],
                    (string) ($invoice['due'] ?? ''),
                    (string) ($invoice['due_source'] ?? ''),
                    (int) $invoice['days_overdue'],
                    (string) $invoice['currency'],
                    (float) $invoice['amount'],
                    $invoice['paid'] !== null ? (float) $invoice['paid'] : '',
                    $invoice['open'] !== null ? (float) $invoice['open'] : '',
                    $invoice['finalized'] ? 'da' : 'nu',
                    $invoice['good_for_payment'] ? 'da' : 'nu',
                    $invoice['correction'] ? 'da' : 'nu',
                    (string) ($invoice['checkin_from'] ?? ''),
                    (string) ($invoice['checkin_to'] ?? ''),
                    (int) $invoice['bookings'],
                    (int) $invoice['lines'],
                    $invoice['etrip_cost'] !== null ? (float) $invoice['etrip_cost'] : '',
                ], $result['invoices']),
            ],
            [
                'name' => 'Linii de factură',
                'headers' => ['Factura', 'Data facturii', 'Dosar', 'Confirmare', 'Serviciu', 'Tip', 'Check-in', 'Check-out', 'Stare furnizor', 'Cantitate', 'Preț unitar', 'Valoare linie', 'TVA', 'Sumă TVA', 'Monedă', 'Cost eTrip serviciu'],
                'widths' => [18, 12, 10, 14, 40, 20, 12, 12, 14, 10, 13, 14, 10, 12, 8, 18],
                'rows' => $lines(),
            ],
            [
                'name' => 'Pe lună de check-in',
                'headers' => ['Luna', 'Monedă', 'Stare', 'Servicii', 'Cost', 'Facturat', 'Diferență', 'Diferență %', 'Servicii nefacturate', 'Cost nefacturat', 'Alertă'],
                'widths' => [10, 8, 12, 10, 14, 14, 14, 12, 20, 16, 9],
                'rows' => array_map(fn (array $month) => [
                    (string) $month['month'],
                    (string) $month['currency'],
                    (string) $month['status'],
                    (int) $month['services'],
                    (float) $month['cost'],
                    (float) $month['billed'],
                    (float) $month['diff'],
                    $month['diff_pct'] !== null ? (float) $month['diff_pct'] : '',
                    (int) $month['unbilled_services'],
                    (float) $month['unbilled_cost'],
                    $month['alert'] ? 'da' : 'nu',
                ], $result['months']),
            ],
            [
                'name' => 'Pe tip de serviciu',
                'headers' => ['Tip', 'Monedă', 'Servicii', 'Cost', 'Facturat', 'Diferență', 'Facturat % (fereastră)', 'Alertă'],
                'widths' => [30, 8, 10, 14, 14, 14, 20, 9],
                'rows' => array_map(fn (array $type) => [
                    (string) $type['label'],
                    (string) $type['currency'],
                    (int) $type['services'],
                    (float) $type['cost'],
                    (float) $type['billed'],
                    (float) $type['diff'],
                    $type['window_billed_pct'] !== null ? (float) $type['window_billed_pct'] : '',
                    $type['alert'] ? 'da' : 'nu',
                ], $result['types']),
            ],
            [
                'name' => 'Plăți',
                'headers' => ['Monedă', 'Plăți', 'Plătit', 'Alocat pe facturi', 'Diferență', 'Prima', 'Ultima'],
                'widths' => [8, 9, 14, 18, 14, 12, 12],
                'rows' => array_map(fn (array $row) => [
                    (string) $row['currency'],
                    (int) ($row['payments'] ?? 0),
                    (float) ($row['paid'] ?? 0),
                    (float) ($row['allocated'] ?? 0),
                    (float) ($row['difference'] ?? 0),
                    (string) ($row['first'] ?? ''),
                    (string) ($row['last'] ?? ''),
                ], $result['payments']),
            ],
        ];

        return XlsxWorkbook::streamDownload(
            sprintf('facturi-servicii-%s-%s-%s.xlsx', Str::slug($supplier->name), $from->format('Ymd'), $to->format('Ymd')),
            $sheets,
        );
    }

    /**
     * Ce i-am facturat și ce i-am plătit furnizorului, din registrul OMC.
     *
     * Verificarea de până acum spunea cât face serviciul după eTrip, dar nu
     * și cât i s-a dat deja. Fără partea asta, omul nu poate judeca dacă
     * suma cerută e îndreptățită: se uita la consum și ghicea restul.
     *
     * Furnizorul din eTrip se leagă de partenerul din ERP prin fișa lui; dacă
     * legătura lipsește, se spune pe față, fiindcă atunci nu e nimic de
     * socotit.
     *
     * @return array<string, mixed>
     */
    public function payments(Request $request, SupplierPaymentCheckService $payments, EtripSupplierSyncService $sync): array
    {
        $validated = $request->validate([
            'connection' => ['required', Rule::in(array_keys((array) config('etrip.connections')))],
            'supplier' => ['required', 'string', 'max:50'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:5'],
        ]);

        $supplier = EtripSupplier::query()
            ->forConnection($validated['connection'])
            ->where('code', $validated['supplier'])
            ->with('partner')
            ->first()
            ?? $this->readingEtrip(fn () => $sync->remember($validated['connection'], $validated['supplier']));

        abort_if($supplier === null, 404, 'Furnizorul nu există în eTrip.');

        $partner = $supplier->partner;

        if ($partner === null) {
            return [
                'linked' => false,
                'supplier' => ['code' => $supplier->code, 'name' => $supplier->name],
            ];
        }

        $requested = isset($validated['amount']) && $validated['amount'] !== ''
            ? (float) $validated['amount']
            : null;

        return [
            'linked' => true,
            ...$payments->check($partner, $requested, $validated['currency'] ?? null),
        ];
    }

    /**
     * Suppliers with the largest check-in cost in the coming days, read live
     * from eTrip unless etrip.expected_cache_minutes asks for a cache.
     *
     * @return array{days: int, from: string, to: string, cached_at: string, suppliers: list<array<string, mixed>>}
     */
    public function expected(Request $request, EtripReader $reader): array
    {
        $validated = $request->validate([
            'connection' => ['required', Rule::in(array_keys((array) config('etrip.connections')))],
            'days' => ['nullable', 'integer', Rule::in(self::EXPECTED_WINDOWS)],
            'refresh' => ['nullable', 'boolean'],
        ]);

        $connection = $validated['connection'];
        $days = (int) ($validated['days'] ?? self::EXPECTED_WINDOWS[0]);
        $from = Carbon::today();
        $to = $from->copy()->addDays($days - 1);
        $key = sprintf('etrip:%s:expected:%d:%s', $connection, $days, $from->toDateString());

        $minutes = (int) config('etrip.expected_cache_minutes', 0);

        if ($request->boolean('refresh') || $minutes <= 0) {
            Cache::forget($key);
        }

        $payload = $this->readingEtrip(fn () => Cache::remember($key, now()->addMinutes(max(1, $minutes)), function () use ($reader, $connection, $from, $to) {
            $known = EtripSupplier::query()
                ->forConnection($connection)
                ->get(['code', 'partner_id'])
                ->keyBy('code');

            return [
                'cached_at' => now()->toIso8601String(),
                'suppliers' => array_map(fn (array $row) => [
                    ...$row,
                    'partner_id' => $known->get($row['supplier_code'])?->partner_id,
                ], $reader->expectedCosts($connection, $from, $to)),
            ];
        }));

        return ['days' => $days, 'from' => $from->toDateString(), 'to' => $to->toDateString(), ...$payload];
    }

    /**
     * @return list<array{key: string, label: string, suppliers_synced_at: ?string}>
     */
    private function bases(): array
    {
        $synced = EtripSupplier::query()
            ->selectRaw('etrip_connection, max(synced_at) as synced_at')
            ->groupBy('etrip_connection')
            ->pluck('synced_at', 'etrip_connection');

        $bases = [];

        foreach ((array) config('etrip.connections') as $key => $label) {
            $bases[] = [
                'key' => $key,
                'label' => (string) $label,
                'suppliers_synced_at' => isset($synced[$key]) ? Carbon::parse($synced[$key])->toIso8601String() : null,
            ];
        }

        return $bases;
    }
}
