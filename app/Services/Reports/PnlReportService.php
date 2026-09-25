<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PnlCostOverride;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Contul de profit și pierdere: venitul și marja din eTrip, cheltuielile de
 * exploatare din registrul jurnal al OMC, pe două axe — canal de vânzare și
 * categorie de produs.
 *
 * Alocarea costurilor, în ordinea asta:
 *
 *  1. **direct**, când cheltuiala are un punct de lucru care e o sucursală
 *     adevărată: chiria de la Sun Plaza e a magazinului Sun Plaza, deci a
 *     retailului;
 *  2. **pe cheia de venit a lunii ei**, pentru tot ce e general (salariile de
 *     la financiar, chiria sediului central). Cheia e a lunii, nu a anului:
 *     altfel chiria din ianuarie s-ar împărți după vânzările din iulie;
 *  3. **pe produs**, în interiorul canalului: costul deja repartizat pe canal se
 *     sparge pe categoriile vândute de acel canal în acea lună.
 *
 * Pasul 3 pornește din rezultatul pasului 1–2 tocmai ca cele două vederi să
 * închidă pe același total: suma pe produse = suma pe canale = totalul
 * companiei. Verificarea e în raport (`reconciles`), nu doar în teste.
 *
 * Raportul se ține în cache pe lună, iar perioada cerută (an, trimestru, lună)
 * se taie din el la afișare — o construcție, orice tăietură.
 */
class PnlReportService
{
    /**
     * Raportul se construiește într-un minut, așa că nu se lasă să expire în
     * timpul zilei: se reface în fiecare noapte și la fiecare corectură, iar
     * între timp pagina trebuie să se deschidă instant. O oră de cache ar
     * însemna „se construiește…” de câteva ori pe zi, degeaba.
     */
    private const CACHE_TTL_SECONDS = 7 * 24 * 3600;

    /** Venitul și costul vânzărilor din eTrip, la data vânzării. */
    public const MODE_OPERATIONAL = 'operational';

    /** Venitul și costul vânzărilor din contabilitate, la data facturării. */
    public const MODE_FINANCIAL = 'financial';

    public const MODES = [self::MODE_OPERATIONAL, self::MODE_FINANCIAL];

    /** Contabilitatea statutară, așa cum e ținută în OMC. */
    public const BASIS_RAS = 'ras';

    /** Chiriile scoase din EBITDA și puse pe amortizare și dobândă, estimat. */
    public const BASIS_IFRS16 = 'ifrs16';

    public const BASES = [self::BASIS_RAS, self::BASIS_IFRS16];

    /** Conturile de personal: punctul de lucru de pe ele e locul unde se face statul de plată, nu centrul de cost. */
    private const PAYROLL_ACCOUNTS = ['641', '642', '645', '646', '647'];

    /** Cheia implicită de repartizare pe magazine: cât vinde fiecare. */
    public const KEY_REVENUE = 'venit';

    /** Cheia finanțelor: cât efectiv ține fiecare magazin, prin masa lui salarială. */
    public const KEY_PAYROLL = 'salarii';

    public const KEYS = [self::KEY_REVENUE, self::KEY_PAYROLL];

    /**
     * Diferențele de curs de pe încasările de la client: un comision, nu un
     * rezultat financiar. Se citește din registru ca tot ce e sub EBITDA, dar
     * raportul îl duce sus, în venituri.
     */
    public const BUCKET_FX_COMMISSION = 'comision_curs';

    public function __construct(
        private EtripPnlReader $etrip,
        private OmcPnlCostReader $costs,
        private PnlCostMap $map,
        private PnlBranchMap $branches,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(Company $company, int $year, bool $forceRefresh = false): array
    {
        $key = $this->cacheKey($company, $year);

        if ($forceRefresh) {
            Cache::forget($key);
        }

        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        try {
            $report = $this->compute($company, $year);
        } catch (Throwable $e) {
            report($e);

            // Un an fără date sau o citire care a depășit timpul nu trebuie să
            // dea pagina peste cap; eroarea NU se ține în cache, ca reîncărcarea
            // să reîncerce.
            return [
                'year' => $year,
                'company' => ['id' => $company->getKey(), 'name' => $company->name],
                'error' => trim($e->getMessage()) !== '' ? trim($e->getMessage()) : $e::class,
            ];
        }

        Cache::put($key, $report, self::CACHE_TTL_SECONDS);

        return $report;
    }

    /**
     * Raportul deja construit, sau null. Pagina web folosește doar asta:
     * construcția durează peste un minut, deci se face în fundal.
     *
     * @return array<string, mixed>|null
     */
    public function cached(Company $company, int $year): ?array
    {
        $report = Cache::get($this->cacheKey($company, $year));

        return is_array($report) && ! isset($report['error']) ? $report : null;
    }

    public function clearCache(Company $company, int $year): void
    {
        Cache::forget($this->cacheKey($company, $year));
    }

    /**
     * Lunile pe care le acoperă o perioadă.
     *
     * Perioada e o lună plus felul în care se citește:
     *  - `ytd7` — de la 1 ianuarie până la sfârșitul lui iulie (cumulat);
     *  - `mtd7` — doar iulie;
     *  - `year` — anul întreg, adică `ytd12`.
     *
     * Trimestrele nu mai au nevoie de opțiuni proprii: T1 e `ytd3`, semestrul
     * e `ytd6`. Formele vechi (`m7`, `q2`) sunt citite mai departe, ca un link
     * salvat să nu ducă în gol.
     *
     * @return list<int>
     */
    public static function months(string $period): array
    {
        if (preg_match('/^mtd(\d{1,2})$/', $period, $matches) && self::inYear((int) $matches[1])) {
            return [(int) $matches[1]];
        }

        if (preg_match('/^(?:ytd|m)(\d{1,2})$/', $period, $matches) && self::inYear((int) $matches[1])) {
            return range(1, (int) $matches[1]);
        }

        if (preg_match('/^q([1-4])$/', $period, $matches)) {
            return range(1, (int) $matches[1] * 3);
        }

        return range(1, 12);
    }

    private static function inYear(int $month): bool
    {
        return $month >= 1 && $month <= 12;
    }

    /**
     * Raportul tăiat pe o perioadă, în forma pe care o desenează pagina.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function view(array $report, string $period, string $basis = self::BASIS_RAS, string $mode = self::MODE_OPERATIONAL, ?string $expand = null, string $key = self::KEY_REVENUE): array
    {
        if (isset($report['error'])) {
            return $report;
        }

        $months = self::months($period);
        $products = $report['products'];

        // Se ține deoparte: `$key` e folosit mai jos ca variabilă de buclă pe
        // canale și produse, iar până la capătul metodei ar ajunge „Charter”.
        $allocationKey = $key;

        // Un canal desfăcut înlocuiește coloana lui cu sucursalele care îl fac:
        // retailul e suma magazinelor, nu o cutie neagră.
        // Doar canalele unde sucursala chiar înseamnă magazinul care a vândut.
        $expandable = array_values(array_intersect(
            array_keys($report['branches'] ?? []),
            array_map('strval', (array) config('pnl.branch_channels', [])),
        ));
        $expand = $expand !== null && in_array($expand, $expandable, true) ? $expand : null;
        $prefix = $expand === null ? '' : $expand.'|';
        $channels = $expand === null
            ? $report['channels']
            : array_values(array_filter(
                $report['branches'][$expand],
                fn (string $branch) => $this->branchHasActivity($report, $prefix.$branch, $months),
            ));
        // Desfăcut pe magazine, costurile comune se împart fie după cât vinde
        // fiecare, fie după masa lui salarială — cheia finanțelor.
        $axis = match (true) {
            $expand === null => 'channel',
            $allocationKey === self::KEY_PAYROLL => 'branch_payroll',
            default => 'branch',
        };

        $revenue = [
            'by_channel' => $this->sumMoney($report['revenue']['months'], $months, $axis, $channels, $prefix),
            'by_product' => $this->sumMoney($report['revenue']['months'], $months, 'product', $products),
            'total' => ['net' => 0.0, 'margin' => 0.0, 'bookings' => 0],
        ];

        if ($expand === null) {
            foreach ($months as $month) {
                $total = $report['revenue']['months'][$month]['total'] ?? null;

                if ($total !== null) {
                    $revenue['total']['net'] += $total['net'];
                    $revenue['total']['margin'] += $total['margin'];
                    $revenue['total']['bookings'] += $total['bookings'];
                }
            }
        } else {
            // Desfăcut, totalul e al canalului deschis, nu al companiei.
            foreach ($revenue['by_channel'] as $money) {
                $revenue['total']['net'] += $money['net'];
                $revenue['total']['margin'] += $money['margin'];
                $revenue['total']['bookings'] += $money['bookings'];
            }
        }

        $revenue['total'] = $this->round($revenue['total']);
        $operationalNet = $revenue['total']['net'];

        if ($mode === self::MODE_FINANCIAL) {
            // Contabilitatea nu știe de canale și de categorii de produs: n-are
            // rezervarea, are factura. Totalurile vin din registru, iar
            // împărțirea pe coloane păstrează structura vânzării din eTrip —
            // altfel vederea financiară n-ar avea coloane deloc.
            $ledger = ['revenue' => 0.0, 'cogs' => 0.0];

            foreach ($months as $month) {
                // Veniturile din exploatare, ca în situațiile financiare: cifra
                // de afaceri plus subvențiile și celelalte venituri de exploatare.
                $ledger['revenue'] += ($report['financial'][$month]['revenue'] ?? 0.0)
                    + ($report['financial'][$month]['other_income'] ?? 0.0);
                $ledger['cogs'] += $report['financial'][$month]['cogs'] ?? 0.0;
            }

            $scale = $operationalNet > 0 ? $ledger['revenue'] / $operationalNet : 0.0;

            // Atenție la nume: `$axis` de mai sus alege axa cheltuielilor
            // („channel” sau „branch”) și se folosește mai jos. Refolosit aici,
            // ar rămâne „by_product”, iar toate coloanele de cost ar ieși zero.
            foreach (['by_channel', 'by_product'] as $revenueAxis) {
                foreach ($revenue[$revenueAxis] as $key => $money) {
                    $share = $operationalNet > 0 ? $money['net'] / $operationalNet : 0.0;
                    $revenue[$revenueAxis][$key] = [
                        'net' => round($ledger['revenue'] * $share, 2),
                        'margin' => round(($ledger['revenue'] - $ledger['cogs']) * $share, 2),
                        'bookings' => $money['bookings'],
                    ];
                }
            }

            $revenue['total'] = [
                'net' => round($ledger['revenue'], 2),
                'margin' => round($ledger['revenue'] - $ledger['cogs'], 2),
                'bookings' => $revenue['total']['bookings'],
            ];
            $revenue['cogs'] = round($ledger['cogs'], 2);
            $revenue['scale'] = round($scale, 6);
        }

        // Comisionul de curs: se citește din registru ca tot ce e sub EBITDA,
        // dar e venit încasat de la client, deci intră în marjă și, prin ea, în
        // EBITDA. Semnul se întoarce: acolo veniturile stau cu minus, fiindcă
        // rândurile de sub EBITDA se scad.
        $fx = ['total' => 0.0, 'by_channel' => array_fill_keys($channels, 0.0), 'by_product' => array_fill_keys($products, 0.0)];

        foreach ($months as $month) {
            $cell = $report['below'][self::BUCKET_FX_COMMISSION]['months'][$month] ?? null;

            if ($cell === null) {
                continue;
            }

            $fx['total'] -= $cell['total'];

            foreach ($cell[$axis] ?? [] as $key => $value) {
                if ($prefix !== '') {
                    if (! str_starts_with((string) $key, $prefix)) {
                        continue;
                    }

                    $key = substr((string) $key, strlen($prefix));
                }

                $fx['by_channel'][$key] = ($fx['by_channel'][$key] ?? 0.0) - $value;
            }

            foreach ($cell['product'] as $key => $value) {
                $fx['by_product'][$key] = ($fx['by_product'][$key] ?? 0.0) - $value;
            }
        }

        $revenue['total']['margin'] = round($revenue['total']['margin'] + $fx['total'], 2);

        foreach (['by_channel' => 'by_channel', 'by_product' => 'by_product'] as $revenueAxis => $fxAxis) {
            foreach ($revenue[$revenueAxis] as $key => $money) {
                $revenue[$revenueAxis][$key]['margin'] = round($money['margin'] + ($fx[$fxAxis][$key] ?? 0.0), 2);
            }
        }

        // Costul născut dintr-o plecare taie marja, nu cheltuiala de structură.
        $tripCosts = ['total' => 0.0, 'by_channel' => array_fill_keys($channels, 0.0), 'by_product' => array_fill_keys($products, 0.0)];

        foreach ($months as $month) {
            $cell = $report['trip']['months'][$month] ?? null;

            if ($cell === null) {
                continue;
            }

            $tripCosts['total'] += $cell['total'];

            foreach ($cell[$axis] ?? [] as $key => $value) {
                if ($prefix !== '') {
                    if (! str_starts_with((string) $key, $prefix)) {
                        continue;
                    }

                    $key = substr((string) $key, strlen($prefix));
                }

                $tripCosts['by_channel'][$key] = ($tripCosts['by_channel'][$key] ?? 0.0) + $value;
            }

            foreach ($cell['product'] as $key => $value) {
                $tripCosts['by_product'][$key] = ($tripCosts['by_product'][$key] ?? 0.0) + $value;
            }
        }

        if (abs($tripCosts['total']) > 0.005) {
            $revenue['total']['margin'] = round($revenue['total']['margin'] - $tripCosts['total'], 2);

            foreach (['by_channel', 'by_product'] as $revenueAxis) {
                foreach ($revenue[$revenueAxis] as $key => $money) {
                    $revenue[$revenueAxis][$key]['margin'] = round($money['margin'] - ($tripCosts[$revenueAxis][$key] ?? 0.0), 2);
                }
            }

            $revenue['trip_costs'] = [
                'label' => (string) config('pnl.trip_costs.label', 'Costuri de cursă'),
                'total' => round($tripCosts['total'], 2),
                'by_channel' => array_map(fn (float $v) => round($v, 2), $tripCosts['by_channel']),
                'by_product' => array_map(fn (float $v) => round($v, 2), $tripCosts['by_product']),
                'accounts' => $report['trip']['accounts'] ?? [],
            ];
        }

        $revenue['fx_commission'] = [
            'label' => (string) config('pnl.fx_commission.label', 'Comision de curs'),
            'total' => round($fx['total'], 2),
            'by_channel' => array_map(fn (float $v) => round($v, 2), $fx['by_channel']),
            'by_product' => array_map(fn (float $v) => round($v, 2), $fx['by_product']),
        ];

        $lines = [];
        $byChannel = array_fill_keys($channels, 0.0);
        $byProduct = array_fill_keys($products, 0.0);
        $byMonth = [];
        $total = 0.0;
        $direct = 0.0;
        $unmapped = 0.0;

        foreach ($report['lines'] as $line) {
            $row = [
                'saf' => $line['saf'],
                'group' => $line['group'],
                'label' => $line['label'],
                'total' => 0.0,
                'direct' => 0.0,
                'by_channel' => array_fill_keys($channels, 0.0),
                'by_product' => array_fill_keys($products, 0.0),
                'by_month' => [],
            ];

            foreach ($months as $month) {
                $cell = $line['months'][$month] ?? null;

                if ($cell === null) {
                    continue;
                }

                $slice = $expand === null
                    ? $cell['total']
                    : array_sum(array_filter(
                        $cell['branch'] ?? [],
                        fn (string $key) => str_starts_with($key, $prefix),
                        ARRAY_FILTER_USE_KEY,
                    ));

                $row['total'] += $slice;
                $row['direct'] += $expand === null ? $cell['direct'] : 0.0;
                $row['by_month'][$month] = round($slice, 2);
                $byMonth[$month] = ($byMonth[$month] ?? 0.0) + $slice;

                foreach ($cell[$axis] ?? [] as $channel => $value) {
                    if ($prefix !== '') {
                        if (! str_starts_with((string) $channel, $prefix)) {
                            continue;
                        }

                        $channel = substr((string) $channel, strlen($prefix));
                    }

                    $row['by_channel'][$channel] = ($row['by_channel'][$channel] ?? 0.0) + $value;
                    $byChannel[$channel] = ($byChannel[$channel] ?? 0.0) + $value;
                }

                $productScale = $expand === null || abs($cell['total']) < 0.005
                    ? 1.0
                    : $slice / $cell['total'];

                foreach ($cell['product'] as $product => $value) {
                    $row['by_product'][$product] = ($row['by_product'][$product] ?? 0.0) + $value * $productScale;
                    $byProduct[$product] = ($byProduct[$product] ?? 0.0) + $value * $productScale;
                }
            }

            if (abs($row['total']) < 0.005) {
                continue;
            }

            $total += $row['total'];
            $direct += $row['direct'];

            if ($row['saf'] === PnlCostMap::UNMAPPED) {
                $unmapped += $row['total'];
            }

            $row['total'] = round($row['total'], 2);
            $row['direct'] = round($row['direct'], 2);
            $row['by_channel'] = array_map(fn (float $v) => round($v, 2), $row['by_channel']);
            $row['by_product'] = array_map(fn (float $v) => round($v, 2), $row['by_product']);
            $lines[] = $row;
        }

        usort($lines, fn (array $a, array $b) => $b['total'] <=> $a['total']);
        ksort($byMonth);

        if ($expand !== null) {
            // Coloanele desfăcute sunt cele care chiar au ceva: vânzare sau
            // cheltuială. O sucursală cu cost și fără vânzare tot trebuie să se
            // vadă, altfel totalul n-ar mai închide.
            $seen = $byChannel;

            foreach ($channels as $channel) {
                $seen[$channel] ??= 0.0;
            }

            arsort($seen);
            $channels = array_keys($seen);

            foreach ($lines as $index => $line) {
                $lines[$index]['by_channel'] = array_replace(array_fill_keys($channels, 0.0), $line['by_channel']);
            }

            $byChannel = array_replace(array_fill_keys($channels, 0.0), $byChannel);
            $revenue['by_channel'] = array_replace(
                array_fill_keys($channels, ['net' => 0.0, 'margin' => 0.0, 'bookings' => 0]),
                $revenue['by_channel'],
            );
        }

        $leases = $basis === self::BASIS_IFRS16
            ? $this->reclassifyLeases($report, $lines, $months, $channels, $products)
            : null;

        if ($leases !== null) {
            // Chiria iese din cheltuielile de exploatare: EBITDA crește cu ea.
            $total -= $leases['expense']['total'];
            $direct -= $leases['expense']['direct'];

            foreach ($leases['expense']['by_channel'] as $key => $value) {
                $byChannel[$key] = ($byChannel[$key] ?? 0.0) - $value;
            }

            foreach ($leases['expense']['by_product'] as $key => $value) {
                $byProduct[$key] = ($byProduct[$key] ?? 0.0) - $value;
            }

            foreach ($leases['expense']['by_month'] as $month => $value) {
                $byMonth[$month] = ($byMonth[$month] ?? 0.0) - $value;
            }

            $lines = array_values(array_map(
                fn (array $line) => in_array($line['saf'], $leases['lines'], true)
                    ? [...$line, 'reclassified' => true]
                    : $line,
                $lines,
            ));
        }

        $below = [];

        foreach (['amortizare', 'financiar', 'impozit'] as $bucket) {
            $row = ['total' => 0.0, 'by_channel' => array_fill_keys($channels, 0.0), 'by_product' => array_fill_keys($products, 0.0)];

            foreach ($months as $month) {
                $cell = $report['below'][$bucket]['months'][$month] ?? null;

                if ($cell === null) {
                    continue;
                }

                $row['total'] += $cell['total'];

                foreach ($cell[$axis] ?? [] as $key => $value) {
                    if ($prefix !== '') {
                        if (! str_starts_with((string) $key, $prefix)) {
                            continue;
                        }

                        $key = substr((string) $key, strlen($prefix));
                    }

                    $row['by_channel'][$key] = ($row['by_channel'][$key] ?? 0.0) + $value;
                }

                foreach ($cell['product'] as $key => $value) {
                    $row['by_product'][$key] = ($row['by_product'][$key] ?? 0.0) + $value;
                }
            }

            if ($leases !== null && isset($leases[$bucket])) {
                $row['total'] += $leases[$bucket]['total'];

                foreach ($leases[$bucket]['by_channel'] as $key => $value) {
                    $row['by_channel'][$key] = ($row['by_channel'][$key] ?? 0.0) + $value;
                }

                foreach ($leases[$bucket]['by_product'] as $key => $value) {
                    $row['by_product'][$key] = ($row['by_product'][$key] ?? 0.0) + $value;
                }
            }

            $accounts = [];

            foreach ($report['below'][$bucket]['accounts'] ?? [] as $account => $byMonth) {
                $sum = 0.0;

                foreach ($months as $month) {
                    $sum += $byMonth[$month] ?? 0.0;
                }

                if (abs($sum) > 0.005) {
                    $accounts[] = [
                        'account' => (string) $account,
                        'label' => self::accountLabel((string) $account),
                        'lei' => round($sum, 2),
                    ];
                }
            }

            usort($accounts, fn (array $a, array $b) => abs($b['lei']) <=> abs($a['lei']));

            $below[$bucket] = [
                'total' => round($row['total'], 2),
                'by_channel' => array_map(fn (float $v) => round($v, 2), $row['by_channel']),
                'by_product' => array_map(fn (float $v) => round($v, 2), $row['by_product']),
                'accounts' => $accounts,
            ];
        }

        return [
            'year' => $report['year'],
            'period' => $period,
            'basis' => $basis,
            'mode' => $mode,
            'key' => $expand === null ? self::KEY_REVENUE : $allocationKey,
            'expand' => $expand,
            'expandable' => $expandable,
            'ifrs16' => $leases === null ? null : $leases['summary'],
            'company' => $report['company'],
            'channels' => $channels,
            'products' => $products,
            'revenue' => $revenue,
            'lines' => $lines,
            'below' => $below,
            'groups' => $report['groups'],
            'totals' => [
                'total' => round($total, 2),
                'by_channel' => array_map(fn (float $v) => round($v, 2), $byChannel),
                'by_product' => array_map(fn (float $v) => round($v, 2), $byProduct),
                'by_month' => array_map(fn (float $v) => round($v, 2), $byMonth),
            ],
            'meta' => [
                ...$report['meta'],
                'direct_lei' => round($direct, 2),
                'allocated_lei' => round($total - $direct, 2),
                'unmapped_lei' => round($unmapped, 2),
                'channels_without_costs' => array_values(array_map('strval', (array) config('pnl.channels_without_costs', []))),
                'reconciles' => abs(array_sum($byChannel) - array_sum($byProduct)) < 0.05
                    && abs(array_sum($byChannel) - $total) < 0.05,
            ],
        ];
    }

    /**
     * Ce s-a adunat într-o linie de cheltuială: documentele din spate, plus
     * gruparea lor pe semnătură (cont | punct de lucru | partener) — unitatea
     * pe care o mută cineva cu mouse-ul pe altă linie.
     *
     * Cu o coloană dată, se arată chiar ce stă în celula aia: fiecare document
     * intră cu partea lui de acolo. O cheltuială legată de un magazin din alt
     * canal nu apare deloc, iar una împărțită pe cheia de venit apare cu felia
     * ei. Fără asta, cine apăsa pe coloana B2B vedea documentele întregii linii
     * și cifre care nu dădeau suma din celulă.
     *
     * @return array{saf: string, label: string, group: string, period: string, total: float, items: list<array<string, mixed>>, documents: list<array<string, mixed>>}
     */
    public function costDetails(Company $company, int $year, string $saf, string $period, ?string $column = null, string $axis = 'channel', int $limit = 400): array
    {
        $this->map->withOverrides(PnlCostOverride::query()->where('company_id', $company->getKey())->get());

        $months = self::months($period);
        $lines = $this->map->lines();
        $keys = $column === null ? null : ($this->cached($company, $year)['keys'] ?? null);
        $documents = [];
        $items = [];
        $byMonth = [];
        $total = 0.0;

        foreach ($this->costs->details($company, $year, $months) as $row) {
            $line = $this->map->map($row['account'], $row['note'], $row['sediu'], $row['partner']);

            if ($line['saf'] !== $saf) {
                continue;
            }

            $share = $keys === null ? 1.0 : $this->weight($row, $line, $column, $axis, $keys);

            // Partea de cost al cursei a ieșit din linie, deci n-are ce căuta
            // nici în detaliul ei: altfel panoul ar aduna mai mult decât celula.
            $share *= 1 - (float) ($row['trip_share'] ?? 0.0);

            if ($share <= 0.0) {
                continue;
            }

            $lei = $row['lei'] * $share;
            $total += $lei;
            $byMonth[$row['month']] = ($byMonth[$row['month']] ?? 0.0) + $lei;
            $documents[] = [...$row, 'lei' => $lei];

            $key = PnlCostOverride::itemKey($row['account'], $row['sediu'], $row['partner']);
            $item = $items[$key] ?? [
                'key' => $key,
                'account' => $row['account'],
                'sediu' => $row['sediu'],
                'partner' => $row['partner'],
                'documents' => 0,
                'lei' => 0.0,
            ];
            $item['documents']++;
            $item['lei'] += $lei;
            $items[$key] = $item;
        }

        usort($documents, fn (array $a, array $b) => abs($b['lei']) <=> abs($a['lei']));
        uasort($items, fn (array $a, array $b) => abs($b['lei']) <=> abs($a['lei']));
        ksort($byMonth);

        $documentCount = count($documents);
        $documents = array_slice($documents, 0, $limit);

        return [
            'saf' => $saf,
            'label' => $lines[$saf]['label'] ?? $saf,
            'group' => $lines[$saf]['group'] ?? '',
            'period' => $period,
            'column' => $keys === null ? null : $column,
            'total' => round($total, 2),
            'by_month' => array_map(fn (float $value) => round($value, 2), $byMonth),
            'items' => array_map(fn (array $item) => [...$item, 'lei' => round($item['lei'], 2)], array_values($items)),
            'documents' => $this->withInvoices($company, $documents),
            'documents_total' => $documentCount,
        ];
    }

    /**
     * Numele contului din planul de conturi, pentru cifrele de sub EBITDA.
     * Fără el, defalcarea ar fi o listă de coduri.
     */
    public static function accountLabel(string $account): string
    {
        $labels = [
            '6811' => 'Amortizarea imobilizărilor',
            '6812' => 'Provizioane',
            '6813' => 'Ajustări pentru deprecierea imobilizărilor',
            '6814' => 'Ajustări pentru deprecierea activelor circulante',
            '7812' => 'Venituri din provizioane (reluări)',
            '7813' => 'Venituri din ajustări pentru imobilizări (reluări)',
            '7814' => 'Venituri din ajustări pentru active circulante (reluări)',
            '786' => 'Venituri financiare din ajustări',
            '665' => 'Cheltuieli din diferențe de curs valutar',
            '666' => 'Cheltuieli privind dobânzile',
            '667' => 'Cheltuieli privind sconturile acordate',
            '668' => 'Alte cheltuieli financiare',
            '765' => 'Venituri din diferențe de curs valutar',
            '766' => 'Venituri din dobânzi',
            '767' => 'Venituri din sconturi obținute',
            '768' => 'Alte venituri financiare',
            '691' => 'Impozit pe profit',
            '698' => 'Impozit pe venit și alte impozite',
        ];

        foreach ([4, 3] as $length) {
            $prefix = substr($account, 0, $length);

            if (isset($labels[$prefix])) {
                return $labels[$prefix];
            }
        }

        return 'Cont '.$account;
    }

    /**
     * Documentele, cu ce știe aplicația despre aceleași facturi: aprobarea,
     * departamentul care le duce, plata. Registrul OMC spune ce s-a înregistrat;
     * tabela noastră spune ce s-a întâmplat cu factura după aceea.
     *
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    private function withInvoices(Company $company, array $documents): array
    {
        if ($documents === []) {
            return [];
        }

        $invoices = Invoice::query()
            ->where('company_id', $company->getKey())
            ->whereIn('nr_doc', array_values(array_unique(array_column($documents, 'nr_doc'))))
            ->whereIn('tip_doc', array_values(array_unique(array_column($documents, 'tip_doc'))))
            ->with(['department:id,name', 'partner:id,name'])
            ->get()
            ->keyBy(fn (Invoice $invoice) => implode('|', [
                $invoice->data_doc?->toDateString(),
                trim((string) $invoice->tip_doc),
                trim((string) $invoice->nr_doc),
            ]));

        return array_map(function (array $row) use ($invoices) {
            $invoice = $invoices->get(implode('|', [$row['data_doc'], $row['tip_doc'], $row['nr_doc']]));

            return [
                ...$row,
                'lei' => round($row['lei'], 2),
                'invoice' => $invoice === null ? null : [
                    'id' => $invoice->id,
                    'approval_status' => $invoice->approval_status,
                    'approval_track' => $invoice->approval_track,
                    'department' => $invoice->department?->name,
                    'payment_status' => $invoice->paymentStatus(),
                    'paid' => round((float) $invoice->val_mon_paid, 2),
                    'closed_at' => $invoice->data_inchidere?->toDateString(),
                    'due' => $invoice->data_scadenta?->toDateString(),
                    'internal' => $invoice->com_int,
                ],
            ];
        }, $documents);
    }

    /**
     * Cât din cheltuiala asta a ajuns în coloana cerută, între 0 și 1.
     *
     * @param  array<string, mixed>  $cost
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $keys
     */
    private function weight(array $cost, array $line, ?string $column, string $axis, array $keys): float
    {
        if ($column === null || $column === '') {
            return 1.0;
        }

        $month = (int) $cost['month'];

        [$channel, $branch, $shares] = $this->placement(
            $cost,
            $line,
            $keys['month_channel'][$month] ?? [],
            $keys['channel'] ?? [],
            array_map('strval', $keys['channels'] ?? []),
        );

        if ($axis === 'branch') {
            // Coloana desfăcută poartă canalul în cheie: „retail|Sun Plaza”.
            [$branchChannel, $branchName] = array_pad(explode('|', $column, 2), 2, '');
            $inChannel = $shares[$branchChannel] ?? 0.0;

            if ($inChannel <= 0.0) {
                return 0.0;
            }

            if ($branch !== null) {
                return $branch === $branchName ? $inChannel : 0.0;
            }

            $byBranch = $this->keys($keys['month_branch'][$month][$branchChannel] ?? [], [], []);

            return $inChannel * ($byBranch[$branchName] ?? 0.0);
        }

        if ($axis === 'product') {
            $productYear = $this->productTotals($keys['month_channel_product'] ?? []);
            $weight = 0.0;

            foreach ($shares as $channelKey => $share) {
                $byProduct = $this->keys($keys['month_channel_product'][$month][$channelKey] ?? [], $productYear, []);
                $weight += $share * ($byProduct === [] ? ($column === '- -' ? 1.0 : 0.0) : ($byProduct[$column] ?? 0.0));
            }

            return $weight;
        }

        return $shares[$column] ?? 0.0;
    }

    /**
     * Retratarea chiriilor după IFRS 16, estimată.
     *
     * Contractele nu sunt în OMC, așa că datoria se deduce din chiria plătită:
     * o chirie anuală L, plătită n ani, actualizată cu r, valorează cât o
     * anuitate. Din ea ies amortizarea (liniar, pe n ani) și dobânda (pe soldul
     * de început). Chiria pleacă din exploatare, deci EBITDA crește cu ea.
     *
     * Amortizarea plus dobânda depășesc de obicei chiria în primii ani și scad
     * sub ea la final — asta e chiar efectul standardului, nu o eroare de
     * calcul.
     *
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $lines
     * @param  list<int>  $months
     * @param  list<string>  $channels
     * @param  list<string>  $products
     * @return array<string, mixed>|null
     */
    private function reclassifyLeases(array $report, array $lines, array $months, array $channels, array $products): ?array
    {
        $wanted = array_map('strval', (array) config('pnl.ifrs16.lines', []));
        $years = max(0.5, (float) config('pnl.ifrs16.term_years', 5));
        $rate = max(0.0, (float) config('pnl.ifrs16.discount_rate', 0.08));

        $expense = [
            'total' => 0.0,
            'direct' => 0.0,
            'by_channel' => array_fill_keys($channels, 0.0),
            'by_product' => array_fill_keys($products, 0.0),
            'by_month' => [],
        ];
        $found = [];

        foreach ($lines as $line) {
            if (! in_array($line['saf'], $wanted, true)) {
                continue;
            }

            $found[] = $line['saf'];
            $expense['total'] += $line['total'];
            $expense['direct'] += $line['direct'];

            foreach ($line['by_channel'] as $key => $value) {
                $expense['by_channel'][$key] = ($expense['by_channel'][$key] ?? 0.0) + $value;
            }

            foreach ($line['by_product'] as $key => $value) {
                $expense['by_product'][$key] = ($expense['by_product'][$key] ?? 0.0) + $value;
            }

            foreach ($line['by_month'] as $month => $value) {
                $expense['by_month'][$month] = ($expense['by_month'][$month] ?? 0.0) + $value;
            }
        }

        if ($found === [] || abs($expense['total']) < 0.005) {
            return null;
        }

        // Chiria întregului an dă datoria; perioada cerută ia partea ei din
        // amortizare și dobândă, ca lunile să adune exact anul.
        $yearExpense = 0.0;

        foreach ($report['lines'] as $line) {
            if (in_array($line['saf'], $wanted, true)) {
                foreach ($line['months'] as $cell) {
                    $yearExpense += $cell['total'];
                }
            }
        }

        $annuity = $rate > 0 ? (1 - (1 + $rate) ** -$years) / $rate : $years;
        $liability = $yearExpense * $annuity;
        $share = abs($yearExpense) > 0.005 ? $expense['total'] / $yearExpense : 0.0;
        $depreciation = $liability / $years * $share;
        $interest = $liability * $rate * $share;

        $spread = fn (float $amount, string $axis) => array_map(
            fn (float $value) => abs($expense['total']) > 0.005 ? $amount * $value / $expense['total'] : 0.0,
            $expense[$axis],
        );

        return [
            'lines' => $found,
            'expense' => $expense,
            'amortizare' => [
                'total' => $depreciation,
                'by_channel' => $spread($depreciation, 'by_channel'),
                'by_product' => $spread($depreciation, 'by_product'),
            ],
            'financiar' => [
                'total' => $interest,
                'by_channel' => $spread($interest, 'by_channel'),
                'by_product' => $spread($interest, 'by_product'),
            ],
            'summary' => [
                'lease_expense' => round($expense['total'], 2),
                'liability' => round($liability, 2),
                'depreciation' => round($depreciation, 2),
                'interest' => round($interest, 2),
                'effect_on_ebitda' => round($expense['total'], 2),
                'effect_on_net' => round($expense['total'] - $depreciation - $interest, 2),
                'term_years' => $years,
                'discount_rate' => $rate,
                'lines' => $found,
            ],
        ];
    }

    /**
     * A vândut sau a cheltuit sucursala ceva în perioada asta? Fără filtrul
     * ăsta, desfacerea retailului ar scoate zeci de coloane goale.
     *
     * @param  array<string, mixed>  $report
     * @param  list<int>  $months
     */
    private function branchHasActivity(array $report, string $branch, array $months): bool
    {
        foreach ($months as $month) {
            if (abs((float) ($report['revenue']['months'][$month]['branch'][$branch]['net'] ?? 0)) > 0.005) {
                return true;
            }
        }

        return false;
    }

    private function cacheKey(Company $company, int $year): string
    {
        // Versiunea crește odată cu forma raportului: un payload vechi, fără
        // cheile noi, s-ar citi tăcut ca zero în loc să fie reconstruit.
        return sprintf('pnl_report:v10:%d:%d', $company->getKey(), $year);
    }

    /**
     * @param  array<int, array<string, mixed>>  $months
     * @param  list<int>  $wanted
     * @param  list<string>  $keys
     * @return array<string, array{net: float, margin: float, bookings: int}>
     */
    private function sumMoney(array $months, array $wanted, string $axis, array $keys, string $prefix = ''): array
    {
        $out = array_fill_keys($keys, ['net' => 0.0, 'margin' => 0.0, 'bookings' => 0]);

        foreach ($wanted as $month) {
            foreach ($months[$month][$axis] ?? [] as $key => $money) {
                if ($prefix !== '') {
                    if (! str_starts_with((string) $key, $prefix)) {
                        continue;
                    }

                    $key = substr((string) $key, strlen($prefix));
                }

                $out[$key] ??= ['net' => 0.0, 'margin' => 0.0, 'bookings' => 0];
                $out[$key]['net'] += $money['net'];
                $out[$key]['margin'] += $money['margin'];
                $out[$key]['bookings'] += $money['bookings'];
            }
        }

        return array_map($this->round(...), $out);
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(Company $company, int $year): array
    {
        $startedAt = microtime(true);

        $overrides = PnlCostOverride::query()->where('company_id', $company->getKey())->get();
        $this->map->withOverrides($overrides);

        $revenue = $this->etrip->byChannelAndProduct($year);
        $costRows = [...$this->costs->costs($company, $year), ...$this->documentMoves($company, $year, $overrides)];

        $channels = array_keys((array) config('pnl.channels', []));
        $products = [];
        $revenueMonths = [];
        $netByMonthChannel = [];
        $netByMonthChannelProduct = [];
        $netByMonthBranch = [];
        $netByChannel = [];
        $branches = [];

        foreach ($revenue as $row) {
            $month = $row['month'];
            $products[$row['product']] = true;

            foreach ([['channel', $row['channel']], ['product', $row['product']]] as [$axis, $key]) {
                $cell = $revenueMonths[$month][$axis][$key] ?? ['net' => 0.0, 'margin' => 0.0, 'bookings' => 0];
                $revenueMonths[$month][$axis][$key] = [
                    'net' => $cell['net'] + $row['net'],
                    'margin' => $cell['margin'] + $row['margin'],
                    'bookings' => $cell['bookings'] + $row['bookings'],
                ];
            }

            $total = $revenueMonths[$month]['total'] ?? ['net' => 0.0, 'margin' => 0.0, 'bookings' => 0];
            $revenueMonths[$month]['total'] = [
                'net' => $total['net'] + $row['net'],
                'margin' => $total['margin'] + $row['margin'],
                'bookings' => $total['bookings'] + $row['bookings'],
            ];

            if ($row['branch'] !== '') {
                // Cheia poartă canalul: aceeași sucursală poate vinde și în
                // retail, și în call center, iar coloanele nu trebuie să le amestece.
                $branchKey = $row['channel'].'|'.$row['branch'];
                $revenueMonths[$month]['branch'][$branchKey] ??= ['net' => 0.0, 'margin' => 0.0, 'bookings' => 0];
                $revenueMonths[$month]['branch'][$branchKey]['net'] += $row['net'];
                $revenueMonths[$month]['branch'][$branchKey]['margin'] += $row['margin'];
                $revenueMonths[$month]['branch'][$branchKey]['bookings'] += $row['bookings'];
                $netByMonthBranch[$month][$row['channel']][$row['branch']] = ($netByMonthBranch[$month][$row['channel']][$row['branch']] ?? 0.0) + $row['net'];
                $branches[$row['channel']][$row['branch']] = ($branches[$row['channel']][$row['branch']] ?? 0.0) + $row['net'];
            }

            $netByMonthChannel[$month][$row['channel']] = ($netByMonthChannel[$month][$row['channel']] ?? 0.0) + $row['net'];
            $netByMonthChannelProduct[$month][$row['channel']][$row['product']] = ($netByMonthChannelProduct[$month][$row['channel']][$row['product']] ?? 0.0) + $row['net'];
            $netByChannel[$row['channel']] = ($netByChannel[$row['channel']] ?? 0.0) + $row['net'];
        }

        $products = array_keys($products);
        sort($products);
        ksort($revenueMonths);

        $payrollByMonthBranch = $this->payrollKey($company, $year, $netByMonthBranch);

        [$lines, $trip] = $this->allocate($costRows, $netByMonthChannel, $netByMonthChannelProduct, $netByMonthBranch, $netByChannel, $channels, $payrollByMonthBranch);
        $below = $this->allocateBelow($company, $year, $netByMonthChannel, $netByMonthChannelProduct, $netByMonthBranch, $netByChannel, $channels, $payrollByMonthBranch);
        $financial = [];

        foreach ($this->costs->revenueAndCogs($company, $year) as $row) {
            $financial[$row['month']][$row['bucket']] = ($financial[$row['month']][$row['bucket']] ?? 0.0) + $row['lei'];
        }

        return [
            'year' => $year,
            'company' => ['id' => $company->getKey(), 'name' => $company->name],
            'channels' => array_values(array_filter($channels, fn (string $c) => isset($netByChannel[$c]))),
            'products' => $products,
            'branches' => array_map(
                function (array $byBranch) {
                    arsort($byBranch);

                    return array_keys($byBranch);
                },
                $branches,
            ),
            'revenue' => ['months' => $revenueMonths],
            // Cheile de repartizare se păstrează, ca deschiderea unei celule să
            // poată arăta ce documente stau chiar în ea, cu aceleași ponderi cu
            // care s-a construit tabelul.
            'keys' => [
                'month_channel' => $netByMonthChannel,
                'month_channel_product' => $netByMonthChannelProduct,
                'month_branch' => $netByMonthBranch,
                'month_branch_payroll' => $payrollByMonthBranch,
                'channel' => $netByChannel,
                'channels' => $channels,
            ],
            'financial' => $financial,
            'lines' => $lines,
            'trip' => $trip,
            'below' => $below,
            'groups' => $this->map->groups(),
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'cost_rows' => count($costRows),
                'excluded_accounts' => OmcPnlCostReader::EXCLUDED_ACCOUNTS,
            ],
        ];
    }

    /**
     * Amortizarea, rezultatul financiar și impozitul, repartizate pe aceeași
     * cheie ca celelalte cheltuieli generale: sunt ale companiei întregi, nu
     * ale unui magazin.
     *
     * @param  array<int, array<string, float>>  $netByMonthChannel
     * @param  array<int, array<string, array<string, float>>>  $netByMonthChannelProduct
     * @param  array<string, float>  $netByChannel
     * @param  list<string>  $channels
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function allocateBelow(Company $company, int $year, array $netByMonthChannel, array $netByMonthChannelProduct, array $netByMonthBranch, array $netByChannel, array $channels, array $payrollByMonthBranch = []): array
    {
        $buckets = [];
        $productYear = $this->productTotals($netByMonthChannelProduct);

        foreach ($this->costs->belowEbitda($company, $year) as $row) {
            $month = $row['month'];
            $lei = $row['lei'];

            // Conturile din spate se păstrează: altfel „Amortizare (net de
            // reluări)” e o cifră pe care nimeni n-o poate verifica, iar când
            // iese negativă — reluări mai mari decât amortizarea anului — pare
            // o greșeală.
            $buckets[$row['bucket']]['accounts'][$row['account']][$month] =
                ($buckets[$row['bucket']]['accounts'][$row['account']][$month] ?? 0.0) + $lei;
            $cell = $buckets[$row['bucket']]['months'][$month] ?? ['total' => 0.0, 'channel' => [], 'product' => [], 'branch' => [], 'branch_payroll' => []];

            // Comisionul de curs e venit, deci se împarte pe tot venitul —
            // inclusiv al francizelor, care e al nostru. Cheltuielile sar
            // peste ele, fiindcă sunt ale francizatului.
            $bearing = $row['bucket'] === self::BUCKET_FX_COMMISSION
                ? fn (array $net) => $net
                : $this->costBearing(...);

            $shares = $this->keys(
                $bearing($netByMonthChannel[$month] ?? []),
                $bearing($netByChannel),
                array_values($bearing(array_flip($channels))),
            );

            $buckets[$row['bucket']]['months'][$month] = $this->spread(
                $cell,
                $lei,
                $shares,
                null,
                $month,
                $netByMonthChannelProduct,
                $productYear,
                $netByMonthBranch,
                null,
                $payrollByMonthBranch,
            );
        }

        return $buckets;
    }

    /**
     * Corecturile pe document, ca perechi de rânduri: factura pleacă din linia
     * pe care au pus-o regulile și ajunge pe cea aleasă de om. Raportul adună
     * pe grupe, nu pe documente, așa că singurul mod de a muta o singură
     * factură e s-o scazi de unde e și s-o aduni unde trebuie.
     *
     * @param  Collection<int, PnlCostOverride>  $overrides
     * @return list<array<string, mixed>>
     */
    private function documentMoves(Company $company, int $year, $overrides): array
    {
        $wanted = $overrides
            ->where('scope', PnlCostOverride::SCOPE_DOCUMENT)
            ->mapWithKeys(fn (PnlCostOverride $o) => [$o->match_key => ['saf' => $o->saf, 'channel' => $o->channel, 'product' => $o->product]])
            ->all();

        if ($wanted === []) {
            return [];
        }

        $rows = [];

        foreach ($this->costs->documentsByKey($company, $year, array_keys($wanted)) as $document) {
            $key = PnlCostOverride::documentKey($document['data_doc'], $document['tip_doc'], $document['nr_doc']);

            if (! isset($wanted[$key])) {
                continue;
            }

            $rows[] = [...$document, 'lei' => -$document['lei']];
            $rows[] = [
                ...$document,
                'force_saf' => $wanted[$key]['saf'],
                'force_channel' => $wanted[$key]['channel'],
                'force_product' => $wanted[$key]['product'],
            ];
        }

        return $rows;
    }

    /**
     * Repartizează fiecare cheltuială pe canale și produse, în luna ei.
     *
     * @param  list<array{account: string, sediu: string, partner: string, note: string, month: int, lei: float}>  $costs
     * @param  array<int, array<string, float>>  $netByMonthChannel
     * @param  array<int, array<string, array<string, float>>>  $netByMonthChannelProduct
     * @param  array<string, float>  $netByChannel
     * @param  list<string>  $channels
     * @return list<array<string, mixed>>
     */
    private function allocate(array $costs, array $netByMonthChannel, array $netByMonthChannelProduct, array $netByMonthBranch, array $netByChannel, array $channels, array $payrollByMonthBranch = []): array
    {
        $lines = [];
        $trip = ['months' => [], 'accounts' => []];
        $productYear = $this->productTotals($netByMonthChannelProduct);

        foreach ($costs as $cost) {
            $line = $this->map->map($cost['account'], $cost['note'], $cost['sediu'], $cost['partner']);

            if (($cost['force_saf'] ?? null) !== null) {
                $line = $this->map->lineFor($cost['force_saf']);
            }

            if (($cost['force_channel'] ?? null) !== null) {
                $line['channel'] = $cost['force_channel'];
            }

            if (($cost['force_product'] ?? null) !== null) {
                $line['product'] = $cost['force_product'];
            }
            $saf = $line['saf'];
            $month = $cost['month'];
            $lei = $cost['lei'];

            [$channel, $branch, $shares] = $this->placement(
                $cost,
                $line,
                $netByMonthChannel[$month] ?? [],
                $netByChannel,
                $channels,
            );

            // Partea născută dintr-o plecare nu e cheltuială de structură: se
            // duce în costul cursei, care taie marja. EBITDA nu se schimbă —
            // marja scade cu exact cât scade cheltuiala de exploatare.
            $tripShare = (float) ($cost['trip_share'] ?? 0.0);

            if ($tripShare > 0.0) {
                $tripLei = $lei * $tripShare;
                $lei -= $tripLei;
                $trip['months'][$month] ??= ['total' => 0.0, 'channel' => [], 'product' => [], 'branch' => [], 'branch_payroll' => []];
                $trip['months'][$month] = $this->spread(
                    $trip['months'][$month],
                    $tripLei,
                    $shares,
                    $branch,
                    $month,
                    $netByMonthChannelProduct,
                    $productYear,
                    $netByMonthBranch,
                    null,
                    $payrollByMonthBranch,
                );
                $trip['accounts'][$cost['account']] = round(($trip['accounts'][$cost['account']] ?? 0.0) + $tripLei, 2);

                if (abs($lei) < 0.005) {
                    continue;
                }
            }

            $lines[$saf] ??= [
                'saf' => $saf,
                'group' => $line['group'],
                'label' => $line['label'],
                'matched_by' => [],
                'months' => [],
            ];

            $lines[$saf]['matched_by'][$line['matched_by']] = round(($lines[$saf]['matched_by'][$line['matched_by']] ?? 0.0) + $lei, 2);

            $cell = $lines[$saf]['months'][$month] ?? ['total' => 0.0, 'direct' => 0.0, 'channel' => [], 'product' => [], 'branch' => [], 'branch_payroll' => []];

            if ($channel !== null) {
                $cell['direct'] += $lei;
            }

            $lines[$saf]['months'][$month] = $this->spread(
                $cell,
                $lei,
                $shares,
                $branch,
                $month,
                $netByMonthChannelProduct,
                $productYear,
                $netByMonthBranch,
                $line['product'] ?? null,
                $payrollByMonthBranch,
            );
        }

        // Celulele NU se rotunjesc: rotunjirea se face o singură dată, când se
        // taie perioada. Altfel, câteva zeci de mii de celule rotunjite fiecare
        // la doi bani duc totalul pe canale și cel pe produse la câțiva bani
        // unul de altul, și raportul s-ar declara singur neînchis.
        return [array_values($lines), $trip];
    }

    /**
     * Masa salarială pe lună, canal și magazin — cheia de repartizare a
     * finanțelor, ca alternativă la cea de venit.
     *
     * Salariile poartă magazinul în analiticul contabil, nu în punctul de
     * lucru. Un magazin care are venit dar n-are salarii identificate primește
     * o masă estimată din venitul lui, la raportul mediu al canalului —
     * altfel ar ieși din repartizare cu totul și ar apărea fără costuri.
     *
     * @param  array<int, array<string, array<string, float>>>  $netByMonthBranch
     * @return array<int, array<string, array<string, float>>>
     */
    private function payrollKey(Company $company, int $year, array $netByMonthBranch): array
    {
        $channelOf = [];

        foreach ($netByMonthBranch as $byChannel) {
            foreach ($byChannel as $channel => $byBranch) {
                foreach (array_keys($byBranch) as $branch) {
                    $channelOf[(string) $branch] = (string) $channel;
                }
            }
        }

        $payroll = [];

        foreach ($this->costs->payrollByPlace($company, $year) as $row) {
            $branch = $this->branches->branchForAnalytic($row['analytic']) ?? $this->branches->branchFor($row['sediu']);

            if ($branch === null || ! isset($channelOf[$branch])) {
                continue;
            }

            $channel = $channelOf[$branch];
            $payroll[$row['month']][$channel][$branch] = ($payroll[$row['month']][$channel][$branch] ?? 0.0) + $row['lei'];
        }

        // Magazinele fără salarii identificate: masă estimată din venit.
        foreach ($netByMonthBranch as $month => $byChannel) {
            foreach ($byChannel as $channel => $byBranch) {
                $known = $payroll[$month][$channel] ?? [];

                if ($known === []) {
                    continue;
                }

                $knownNet = 0.0;

                foreach (array_keys($known) as $branch) {
                    $knownNet += $byBranch[$branch] ?? 0.0;
                }

                $ratio = $knownNet > 0 ? array_sum($known) / $knownNet : 0.0;

                foreach ($byBranch as $branch => $net) {
                    if (! isset($known[$branch]) && $net > 0 && $ratio > 0) {
                        $payroll[$month][$channel][$branch] = $net * $ratio;
                    }
                }
            }
        }

        return $payroll;
    }

    /**
     * Împarte o sumă pe canale, produse și sucursale, în celula lunii.
     *
     * Aceeași aritmetică pentru linia de cheltuială și pentru costul cursei:
     * dacă ar fi scrisă de două ori, cele două ar începe cândva să nu mai dea
     * același total pe coloane.
     *
     * @param  array<string, mixed>  $cell
     * @param  array<string, float>  $shares
     * @param  array<int, array<string, array<string, float>>>  $netByMonthChannelProduct
     * @param  array<string, float>  $productYear
     * @param  array<int, array<string, array<string, float>>>  $netByMonthBranch
     * @return array<string, mixed>
     */
    private function spread(array $cell, float $lei, array $shares, ?string $branch, int $month, array $netByMonthChannelProduct, array $productYear, array $netByMonthBranch, ?string $product = null, array $payrollByMonthBranch = []): array
    {
        $cell['total'] += $lei;

        foreach ($shares as $channelKey => $share) {
            $channelLei = $lei * $share;
            $cell['channel'][$channelKey] = ($cell['channel'][$channelKey] ?? 0.0) + $channelLei;

            // Produsul cerut de o corectură bate cheia, la fel ca la canal:
            // o campanie pentru croaziere nu e a tuturor produselor.
            $productShares = $product !== null
                ? [$product => 1.0]
                // Produsele vândute de canalul ăla în luna aia; dacă n-a vândut
                // nimic, cele ale anului, ca banii să nu rămână fără categorie.
                : $this->keys($netByMonthChannelProduct[$month][$channelKey] ?? [], $productYear, []);

            foreach ($productShares as $productKey => $productShare) {
                $cell['product'][$productKey] = ($cell['product'][$productKey] ?? 0.0) + $channelLei * $productShare;
            }

            if ($productShares === []) {
                $cell['product']['- -'] = ($cell['product']['- -'] ?? 0.0) + $channelLei;
            }

            // Înăuntrul canalului, pe magazinul care a suportat-o: direct
            // dacă are punctul lui de lucru, altfel pe o cheie.
            //
            // Cheia se alege din raport, așa că se calculează amândouă: după
            // vânzarea magazinului și după masa lui salarială. A doua e cheia
            // finanțelor — cât efectiv ține magazinul, nu cât vinde — și dă
            // alt răspuns pentru un magazin nou sau unul cu sezon slab.
            foreach (['branch' => $netByMonthBranch, 'branch_payroll' => $payrollByMonthBranch] as $axis => $weights) {
                if ($branch !== null) {
                    $cell[$axis][$channelKey.'|'.$branch] = ($cell[$axis][$channelKey.'|'.$branch] ?? 0.0) + $channelLei;

                    continue;
                }

                // Fără masă salarială pe canalul ăla, cheia de venit ține locul:
                // banii trebuie să ajungă undeva.
                $byBranch = $this->keys($weights[$month][$channelKey] ?? [], [], []);

                if ($byBranch === [] && $axis === 'branch_payroll') {
                    $byBranch = $this->keys($netByMonthBranch[$month][$channelKey] ?? [], [], []);
                }

                foreach ($byBranch as $branchKey => $branchShare) {
                    $cell[$axis][$channelKey.'|'.$branchKey] = ($cell[$axis][$channelKey.'|'.$branchKey] ?? 0.0) + $channelLei * $branchShare;
                }
            }
        }

        return $cell;
    }

    /**
     * Unde cade o cheltuială: pe ce canal, pe ce sucursală și, dacă nu se poate
     * lipi de niciunul, cu ce ponderi se împarte pe cheia de venit.
     *
     * Stă separat fiindcă are două chemări: o dată când se construiește
     * raportul, și o dată când cineva deschide o celulă și vrea să vadă chiar
     * documentele din spatele ei. Dacă regula ar fi scrisă de două ori,
     * detaliul ar ajunge, într-o zi, să nu mai dea suma din tabel.
     *
     * @param  array<string, mixed>  $cost
     * @param  array<string, mixed>  $line
     * @param  array<string, float>  $netThisMonth
     * @param  array<string, float>  $netByChannel
     * @param  list<string>  $channels
     * @return array{0: ?string, 1: ?string, 2: array<string, float>}
     */
    private function placement(array $cost, array $line, array $netThisMonth, array $netByChannel, array $channels): array
    {
        // Canalul cerut de o corectură bate totul: e o decizie de om, nu o
        // deducere din punctul de lucru sau din cheia de venit.
        $channel = $line['channel']
            ?? ($this->isPayroll($cost['account']) ? null : $this->branches->channel($cost['sediu']));

        // Un punct de lucru de franciză nu ne aduce cheltuiala nouă; doar o
        // mutare făcută de om o poate pune acolo.
        if (($line['channel'] ?? null) === null && $channel !== null && $this->costBearing([$channel => 0]) === []) {
            $channel = null;
        }

        $branch = ($line['channel'] ?? null) !== null || $channel === null
            ? null
            : $this->branches->branchFor($cost['sediu']);

        $shares = $channel !== null
            ? [$channel => 1.0]
            : $this->keys(
                $this->costBearing($netThisMonth),
                $this->costBearing($netByChannel),
                array_values($this->costBearing(array_flip($channels))),
            );

        return [$channel, $branch, $shares];
    }

    /**
     * @param  array<int, array<string, array<string, float>>>  $netByMonthChannelProduct
     * @return array<string, float>
     */
    private function productTotals(array $netByMonthChannelProduct): array
    {
        $totals = [];

        foreach ($netByMonthChannelProduct as $channels) {
            foreach ($channels as $products) {
                foreach ($products as $product => $net) {
                    $totals[$product] = ($totals[$product] ?? 0.0) + $net;
                }
            }
        }

        return $totals;
    }

    /**
     * Cheia de venit fără canalele care nu duc cheltuieli. Francizele își
     * plătesc singure costurile, deci nu pot primi o parte din ale noastre.
     *
     * @param  array<string, float>  $net
     * @return array<string, float>
     */
    private function costBearing(array $net): array
    {
        return array_diff_key($net, array_flip(array_map(
            'strval',
            (array) config('pnl.channels_without_costs', []),
        )));
    }

    /**
     * Cheia de repartizare: ponderea fiecărei chei în venitul net. Fără venit
     * în luna ei, se cade pe cheia de rezervă (anul); fără nici aia, se împarte
     * egal pe cheile date, ca banii să nu dispară din raport.
     *
     * @param  array<string, float>  $net
     * @param  array<string, float>  $fallbackNet
     * @param  list<string>  $fallbackKeys
     * @return array<string, float>
     */
    private function keys(array $net, array $fallbackNet, array $fallbackKeys): array
    {
        foreach ([$net, $fallbackNet] as $candidate) {
            $positive = array_filter($candidate, fn (float $value) => $value > 0);

            if ($positive !== []) {
                $sum = array_sum($positive);

                return array_map(fn (float $value) => $value / $sum, $positive);
            }
        }

        if ($fallbackKeys === []) {
            return [];
        }

        return array_fill_keys($fallbackKeys, 1 / count($fallbackKeys));
    }

    private function isPayroll(string $account): bool
    {
        foreach (self::PAYROLL_ACCOUNTS as $prefix) {
            if (str_starts_with($account, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{net: float, margin: float, bookings: int}  $row
     * @return array{net: float, margin: float, bookings: int}
     */
    private function round(array $row): array
    {
        return ['net' => round($row['net'], 2), 'margin' => round($row['margin'], 2), 'bookings' => $row['bookings']];
    }
}
