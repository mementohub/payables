<?php

namespace App\Services\Forecast;

use App\Models\ForecastHistory;
use App\Models\Lease;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Services\Reports\Ifrs16Schedule;
use App\Services\Reports\PnlReportService;
use Illuminate\Support\Collection;

/**
 * Din pârghii iese raportul: venit, marjă, cheltuieli, EBITDA și profit,
 * lună de lună, alături de anul din care s-a pornit.
 *
 * Nu se inventează nimic aici: fiecare cifră e o înmulțire pe care o poate
 * reface omul cu creionul — pasageri × venit pe pasager, locuri × grad de
 * ocupare × venit pe pasager — iar ce nu se poate plănui din pârghii
 * (amortizarea, dobânda, impozitul) se poartă din anul de bază, de unde se
 * poate schimba cu mâna.
 */
class PlanEngine
{
    public function __construct(private PnlReportService $pnl) {}

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  string  $period  an, trimestru sau cumul, ca în raport („year”, „q3”, „ytd9”, „mtd9”)
     * @param  string  $basis  statutar sau IFRS 16, ca în raport
     */
    public function compute(
        Plan $plan,
        string $period = 'year',
        string $basis = PnlReportService::BASIS_RAS,
        string $mode = PnlReportService::MODE_OPERATIONAL,
    ): array {
        $lines = $plan->lines()->get();
        $wanted = PnlReportService::months($period);

        // Anul de bază, citit cu aceeași măsură: aceeași perioadă, aceeași
        // bază de chirii, aceeași recunoaștere a venitului. Altfel coloana
        // de alături ar fi un alt raport, nu termenul de comparație.
        $actual = $this->actuals($plan, $wanted, $period, $basis, $mode);

        // Chiriile retratate ca în raport: ies din exploatare și se întorc ca
        // amortizare și dobândă, după contractele din registru.
        $leases = $basis === PnlReportService::BASIS_IFRS16 ? $this->leases($plan) : null;

        $months = [];

        foreach (range(1, 12) as $month) {
            $months[$month] = ['net' => 0.0, 'margin' => 0.0, 'opex' => 0.0, 'below' => 0.0, 'pax' => 0.0, 'services' => 0.0, 'seats' => 0.0, 'empty_seats' => 0.0, 'empty_cost' => 0.0];
        }

        // Operațional înseamnă la data rezervării, financiar la data plecării.
        // Categoriile sunt plănuite pe rezervare (luna din eTrip e a
        // rezervării), charterul pe plecare (luna e a cursei), deci fiecare
        // vedere mută numai ce nu stă deja pe baza ei — după tiparul din
        // istoric, nu după o presupunere.
        $lag = $this->lag($plan);
        $shifted = false;

        // Venitul și marja, strânse și pe coloane: pe categorie de produs și
        // pe canal de vânzare, ca în raport. Se adună în aceeași trecere cu
        // lunile, ca să prindă și mutarea dintre vederi.
        $mix = $this->mix($plan);
        $axes = ['product' => [], 'channel' => []];

        foreach ($lines as $line) {
            $month = (int) $line->month;

            if (! isset($months[$month])) {
                continue;
            }

            if (in_array($line->kind, [PlanLine::CATEGORY, PlanLine::CHARTER], true)) {
                // Charterul se plănuiește pe cursă, dar se citește pe
                // categoria lui: o coloană „Charter”, ca în P&L.
                $key = $line->kind === PlanLine::CHARTER ? PlanBuilder::CHARTER_CATEGORY : (string) $line->key;
                $way = $lag === null ? null : $this->way($line, $mode);
                $profile = $way === null ? [$month => 1.0] : ($lag[$way][$key][$month] ?? [$month => 1.0]);
                $shifted = $shifted || ($way !== null && count($profile) > 1);

                foreach ($profile as $to => $share) {
                    if (! isset($months[$to]) || ! in_array($to, $wanted, true)) {
                        continue;
                    }

                    $net = $line->net() * $share;
                    $margin = $line->margin() * $share;

                    $months[$to] = [
                        ...$months[$to],
                        'net' => $months[$to]['net'] + $net,
                        'margin' => $months[$to]['margin'] + $margin,
                        // Pasagerii și serviciile se numără în coloane
                        // diferite: un bilet de avion vândut unei firme nu e
                        // un pasager plecat în vacanță.
                        'pax' => $months[$to]['pax'] + ($line->unit === PlanLine::SERVICE ? 0 : $line->pax() * $share),
                        'services' => $months[$to]['services'] + ($line->unit === PlanLine::SERVICE ? $line->pax() * $share : 0),
                        'seats' => $months[$to]['seats'] + (float) ($line->capacity ?? 0) * $share,
                        'empty_seats' => $months[$to]['empty_seats'] + $line->emptySeats() * $share,
                        'empty_cost' => $months[$to]['empty_cost'] + $line->emptyCost() * $share,
                    ];

                    $this->pour($axes['product'], $key, $to, $net, $margin, $line->unit === PlanLine::SERVICE ? 0 : $line->pax() * $share, $line->unit === PlanLine::SERVICE ? $line->pax() * $share : 0);

                    foreach ($mix[$key] ?? ['—' => 1.0] as $channel => $cut) {
                        $this->pour($axes['channel'], (string) $channel, $to, $net * $cut, $margin * $cut, 0, 0);
                    }
                }

                continue;
            }

            if (! in_array($month, $wanted, true)) {
                continue;
            }

            match ($line->kind) {
                PlanLine::OPEX => $months[$month]['opex'] += (float) $line->amount,
                PlanLine::BELOW => $months[$month]['below'] += (float) $line->amount,
                default => null,
            };
        }

        // Chiria iese din cheltuieli, amortizarea și dobânda intră sub EBITDA.
        if ($leases !== null) {
            foreach ($months as $month => $cell) {
                // Numai lunile cerute: altfel o lună singură ar scădea chiria
                // întregului an din cheltuielile ei.
                if (! in_array($month, $wanted, true)) {
                    continue;
                }

                $months[$month]['opex'] = round($cell['opex'] - (float) ($leases['rent'][$month] ?? 0), 2);
                $months[$month]['below'] = round($cell['below'] + (float) ($leases['below'][$month] ?? 0), 2);
            }
        }

        foreach ($months as $month => $cell) {
            $months[$month]['ebitda'] = round($cell['margin'] - $cell['opex'], 2);
            $months[$month]['net_profit'] = round($cell['margin'] - $cell['opex'] - $cell['below'], 2);
            $months[$month] = array_map(fn ($value) => round((float) $value, 2), $months[$month]);
        }

        return [
            'months' => $months,
            'totals' => $this->totals($months),
            // Planul în forma raportului: aceleași rânduri, în aceeași
            // ordine, ca să se poată citi unul lângă altul cu P&L-ul
            // adevărat, nu ca două tabele care seamănă.
            'statement' => $this->statement($plan, $lines->whereIn('month', $wanted), $months, $leases, $actual, $axes),
            // Coloanele raportului: aceleași rânduri, citite pe categorie de
            // produs și pe canal de vânzare.
            'columns' => [
                'product' => $this->columns($axes['product']),
                'channel' => $this->columns($axes['channel']),
            ],
            'period' => $period,
            'basis' => $basis,
            'mode' => $mode,
            'shifted' => $shifted,
            // Același venit, citit pe canale de vânzare: amestecul fiecărei
            // categorii se ia din anul de bază, nu se inventează.
            'channels' => array_map(
                fn (array $cell) => ['channel' => $cell['key'], 'net' => $cell['net'], 'margin' => $cell['margin'], 'months' => $cell['months']],
                $this->columns($axes['channel']),
            ),
            'leases' => $leases === null ? null : $leases['summary'],
            'categories' => $this->byKey($lines->whereIn('kind', [PlanLine::CATEGORY, PlanLine::CHARTER])),
            'charter' => $this->byKey($lines->where('kind', PlanLine::CHARTER)),
            'opex' => $this->byKey($lines->where('kind', PlanLine::OPEX)),
            'below' => $this->byKey($lines->where('kind', PlanLine::BELOW)),
            'base' => [
                'year' => $plan->base_year,
                'built' => $actual !== null,
                ...($actual['totals'] ?? []),
            ],
        ];
    }

    /**
     * Amestecul de canale al fiecărei categorii, din anul de bază.
     *
     * Planul se face pe categorie de produs, fiindcă acolo stau pârghiile —
     * pasagerii, locurile, banii pe pasager. Dar raportul se citește și pe
     * canal, iar proporția se ia din anul de bază: dacă Circuitele Culturale
     * s-au vândut 60% prin agenții și 40% prin B2B, planul lor se împarte la
     * fel, până se schimbă cu mâna.
     *
     * @return array<string, array<string, float>>
     */
    private function mix(Plan $plan): array
    {
        $rows = ForecastHistory::query()
            ->where('company_id', $plan->company_id)
            ->where('year', $plan->base_year)
            ->where('kind', 'mix')
            ->get()
            ->groupBy('key');

        $out = [];

        foreach ($rows as $key => $group) {
            $total = (float) $group->sum('net');

            if ($total <= 0) {
                continue;
            }

            foreach ($group->groupBy('channel') as $channel => $cells) {
                $out[(string) $key][(string) $channel] = (float) $cells->sum('net') / $total;
            }
        }

        return $out;
    }

    /**
     * Adună o fărâmă de venit într-o coloană, pe luna ei.
     *
     * @param  array<string, array<string, mixed>>  $axis
     */
    private function pour(array &$axis, string $key, int $month, float $net, float $margin, float $pax, float $services): void
    {
        $cell = $axis[$key] ?? ['key' => $key, 'net' => 0.0, 'margin' => 0.0, 'pax' => 0.0, 'services' => 0.0, 'months' => array_fill_keys(range(1, 12), 0.0)];

        $cell['net'] += $net;
        $cell['margin'] += $margin;
        $cell['pax'] += $pax;
        $cell['services'] += $services;
        $cell['months'][$month] += $net;

        $axis[$key] = $cell;
    }

    /**
     * Coloanele, rotunjite și puse în ordinea banilor, ca în raport.
     *
     * @param  array<string, array<string, mixed>>  $axis
     * @return list<array<string, mixed>>
     */
    private function columns(array $axis): array
    {
        uasort($axis, fn (array $a, array $b) => $b['net'] <=> $a['net']);

        return array_values(array_map(fn (array $cell) => [
            ...$cell,
            'net' => round((float) $cell['net'], 2),
            'margin' => round((float) $cell['margin'], 2),
            'pax' => round((float) $cell['pax'], 2),
            'services' => round((float) $cell['services'], 2),
            'months' => array_map(fn (float $value) => round($value, 2), $cell['months']),
        ], $axis));
    }

    /**
     * Împarte o cheltuială pe coloane, după o cheie de împărțire.
     *
     * @param  array<string, float>  $shares
     * @return array<string, float>
     */
    private function allocate(float $amount, array $shares): array
    {
        return array_map(fn (float $share) => round($amount * $share, 2), $shares);
    }

    /**
     * Planul, rând cu rând, ca în contul de profit și pierdere.
     *
     * @param  Collection<int, PlanLine>  $lines
     * @param  array<int, array<string, float>>  $months
     * @return list<array<string, mixed>>
     */
    private function statement(Plan $plan, Collection $lines, array $months, ?array $leases = null, ?array $actual = null, array $axes = ['product' => [], 'channel' => []]): array
    {
        $rows = [];
        $pick = fn (string $key) => array_map(fn (array $cell) => (float) ($cell[$key] ?? 0), $months);

        // Cheia după care se împarte o cheltuială pe coloane: proporția în
        // care s-a împărțit aceeași linie în anul de bază. Linia nouă, care
        // n-a existat anul trecut, se împarte după venit.
        $spread = fn (string $key, string $axis) => $actual['shares'][$axis][$key]
            ?? $this->share($axes[$axis] ?? [], 'net');

        $revenue = [
            'product' => $this->share($axes['product'] ?? [], 'net', false),
            'channel' => $this->share($axes['channel'] ?? [], 'net', false),
        ];
        $margin = [
            'product' => $this->share($axes['product'] ?? [], 'margin', false),
            'channel' => $this->share($axes['channel'] ?? [], 'margin', false),
        ];

        $rows[] = $this->row('revenue', 'Venituri nete', 'strong', $pick('net'), null, null, $revenue);
        $rows[] = $this->row('margin', 'Marjă brută', 'strong', $pick('margin'), null, null, $margin);

        // Cheltuielile se strâng pe grupele raportului, cu liniile sub ele.
        $groups = [];

        $rentLines = array_map('strval', (array) config('pnl.ifrs16.lines', []));

        foreach ($lines->where('kind', PlanLine::OPEX) as $line) {
            // Pe IFRS 16, liniile de chirie nu mai stau în exploatare.
            if ($leases !== null && in_array((string) $line->key, $rentLines, true)) {
                continue;
            }

            [$group, $label] = array_pad(explode(' · ', (string) $line->label, 2), 2, '');
            $group = $group !== '' ? $group : 'Alte cheltuieli';

            $groups[$group]['months'][(int) $line->month] = ($groups[$group]['months'][(int) $line->month] ?? 0) + (float) $line->amount;
            $groups[$group]['lines'][$line->key]['label'] = $label !== '' ? $label : $line->key;
            $groups[$group]['lines'][$line->key]['months'][(int) $line->month] =
                ($groups[$group]['lines'][$line->key]['months'][(int) $line->month] ?? 0) + (float) $line->amount;
        }

        // Cheltuielile pe coloane, linie cu linie; grupa e suma liniilor ei,
        // iar „Cheltuieli totale” suma grupelor — ca să se închidă pe
        // orizontală, nu doar pe verticală.
        $costs = ['product' => [], 'channel' => []];

        foreach ($groups as $group => $cells) {
            foreach ($cells['lines'] as $saf => $line) {
                foreach (['product', 'channel'] as $axis) {
                    $cut = $this->allocate(array_sum($line['months']), $spread('line:'.$saf, $axis));
                    $groups[$group]['lines'][$saf]['cut'][$axis] = $cut;
                    $groups[$group]['cut'][$axis] = $this->add($groups[$group]['cut'][$axis] ?? [], $cut);
                    $costs[$axis] = $this->add($costs[$axis], $cut);
                }
            }
        }

        uasort($groups, fn (array $a, array $b) => array_sum($b['months']) <=> array_sum($a['months']));

        foreach ($groups as $group => $cells) {
            $rows[] = $this->row('group:'.$group, $group, 'group', $this->spread($cells['months']), count($cells['lines']), null, $cells['cut'] ?? []);

            uasort($cells['lines'], fn (array $a, array $b) => array_sum($b['months']) <=> array_sum($a['months']));

            foreach ($cells['lines'] as $saf => $line) {
                $rows[] = $this->row('line:'.$saf, $saf.' '.$line['label'], 'line', $this->spread($line['months']), null, $group, $line['cut'] ?? []);
            }
        }

        $rows[] = $this->row('costs', 'Cheltuieli totale operaționale', 'strong', $pick('opex'), null, null, $costs);
        $rows[] = $this->row('ebitda', 'EBITDA', 'result', $pick('ebitda'), null, null, [
            'product' => $this->sub($margin['product'], $costs['product']),
            'channel' => $this->sub($margin['channel'], $costs['channel']),
        ]);

        $under = ['product' => [], 'channel' => []];

        foreach ($lines->where('kind', PlanLine::BELOW)->groupBy('key') as $bucket => $group) {
            $cells = [];

            foreach ($group as $line) {
                $cells[(int) $line->month] = ($cells[(int) $line->month] ?? 0) + (float) $line->amount;
            }

            $cut = [];

            foreach (['product', 'channel'] as $axis) {
                $cut[$axis] = $this->allocate(array_sum($cells), $spread('below:'.$bucket, $axis));
                $under[$axis] = $this->add($under[$axis], $cut[$axis]);
            }

            $rows[] = $this->row(
                'below:'.$bucket,
                PnlReportService::BELOW_LABELS[$bucket] ?? (string) $bucket,
                'cost',
                $this->spread($cells),
                null,
                null,
                $cut,
            );
        }

        if ($leases !== null) {
            foreach ([['depreciation', 'Amortizare drept de utilizare (IFRS 16)'], ['interest', 'Dobândă datorie de leasing (IFRS 16)']] as [$piece, $label]) {
                $cut = [];

                foreach (['product', 'channel'] as $axis) {
                    // Chiria retratată n-are linie în anul de bază: se împarte
                    // după venit, ca orice cheltuială comună.
                    $cut[$axis] = $this->allocate(array_sum($leases[$piece]), $this->share($axes[$axis] ?? [], 'net'));
                    $under[$axis] = $this->add($under[$axis], $cut[$axis]);
                }

                $rows[] = $this->row('leases:'.$piece, $label, 'cost', $this->spread($leases[$piece]), null, null, $cut);
            }
        }

        $rows[] = $this->row('net', 'Profit net', 'result', $pick('net_profit'), null, null, [
            'product' => $this->sub($this->sub($margin['product'], $costs['product']), $under['product']),
            'channel' => $this->sub($this->sub($margin['channel'], $costs['channel']), $under['channel']),
        ]);

        if ($actual === null) {
            return $rows;
        }

        return array_map(fn (array $row) => [
            ...$row,
            'base' => isset($actual['rows'][$row['key']]) ? round($actual['rows'][$row['key']], 2) : null,
        ], $rows);
    }

    /**
     * Încotro se mută o linie, ca să fie citită pe baza cerută.
     *
     * Categoriile stau pe luna rezervării: pe financiar se duc înainte, în
     * lunile plecării. Charterul stă pe luna cursei: pe operațional se
     * întoarce înapoi, în lunile în care s-au vândut locurile. Ce e deja pe
     * baza cerută nu se atinge.
     */
    private function way(PlanLine $line, string $mode): ?string
    {
        if ($line->kind === PlanLine::CHARTER) {
            return $mode === PnlReportService::MODE_OPERATIONAL ? 'backward' : null;
        }

        if ($line->kind === PlanLine::CATEGORY) {
            return $mode === PnlReportService::MODE_FINANCIAL ? 'forward' : null;
        }

        return null;
    }

    /**
     * Tiparul după care se mută venitul dintr-o vedere în alta.
     *
     * Din istoric se știe, pentru fiecare categorie, cât din ce s-a vândut
     * într-o lună a plecat în fiecare lună. Citit înainte, tiparul duce
     * rezervarea la plecare; citit înapoi, duce plecarea la rezervare.
     *
     * @return array{forward: array<string, array<int, array<int, float>>>, backward: array<string, array<int, array<int, float>>>}|null
     */
    private function lag(Plan $plan): ?array
    {
        $rows = ForecastHistory::query()
            ->where('company_id', $plan->company_id)
            ->where('year', $plan->base_year)
            ->where('kind', 'lag')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $out = ['forward' => [], 'backward' => []];

        foreach ($rows as $row) {
            $booked = (int) $row->month;
            $travelled = (int) $row->channel;
            $weight = abs((float) $row->net);

            $out['forward'][$row->key][$booked][$travelled] = ($out['forward'][$row->key][$booked][$travelled] ?? 0) + $weight;
            $out['backward'][$row->key][$travelled][$booked] = ($out['backward'][$row->key][$travelled][$booked] ?? 0) + $weight;
        }

        foreach ($out as $way => $keys) {
            foreach ($keys as $key => $byMonth) {
                foreach ($byMonth as $from => $shares) {
                    $total = array_sum($shares);

                    if ($total <= 0) {
                        continue;
                    }

                    $out[$way][$key][$from] = array_map(fn (float $value) => $value / $total, $shares);
                }
            }
        }

        return $out;
    }

    /**
     * Chiriile retratate pentru anul planului, din registrul de contracte.
     *
     * @return array<string, mixed>|null
     */
    private function leases(Plan $plan): ?array
    {
        $leases = Lease::query()->where('company_id', $plan->company_id)->counted()->get();

        if ($leases->isEmpty()) {
            return null;
        }

        $schedule = app(Ifrs16Schedule::class)->forYear($leases, (int) $plan->year);
        $rent = [];
        $below = [];
        $depreciation = [];
        $interest = [];

        foreach (range(1, 12) as $month) {
            $cell = $schedule['months'][$month] ?? ['payment' => 0.0, 'depreciation' => 0.0, 'interest' => 0.0];
            $rent[$month] = round((float) $cell['payment'], 2);
            $depreciation[$month] = round((float) $cell['depreciation'], 2);
            $interest[$month] = round((float) $cell['interest'], 2);
            $below[$month] = round($depreciation[$month] + $interest[$month], 2);
        }

        return [
            'rent' => $rent,
            'below' => $below,
            'depreciation' => $depreciation,
            'interest' => $interest,
            'summary' => [
                'leases' => (int) $schedule['leases'],
                'rent' => round(array_sum($rent), 2),
                'depreciation' => round(array_sum($depreciation), 2),
                'interest' => round(array_sum($interest), 2),
            ],
            'months' => range(1, 12),
        ];
    }

    /**
     * @param  array<int, float>  $months
     * @return array<int, float>
     */
    private function spread(array $months): array
    {
        $out = [];

        foreach (range(1, 12) as $month) {
            $out[$month] = round((float) ($months[$month] ?? 0), 2);
        }

        return $out;
    }

    /**
     * @param  array<int, float>  $months
     * @return array<string, mixed>
     */
    private function row(string $key, string $label, string $kind, array $months, ?int $lines = null, ?string $group = null, array $cut = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'group' => $group,
            'lines' => $lines,
            'months' => array_map(fn (float $value) => round($value, 2), $months),
            'total' => round(array_sum($months), 2),
            // Aceeași cifră, citită pe coloane: pe categorie de produs și pe
            // canal de vânzare.
            'product' => array_map(fn (float $value) => round($value, 2), $cut['product'] ?? []),
            'channel' => array_map(fn (float $value) => round($value, 2), $cut['channel'] ?? []),
        ];
    }

    /**
     * Cât ține fiecare coloană din totalul ei — sau chiar cifrele, nerupte.
     *
     * @param  list<array<string, mixed>>|array<string, array<string, mixed>>  $axis
     * @return array<string, float>
     */
    private function share(array $axis, string $field, bool $asShare = true): array
    {
        $out = [];

        foreach ($axis as $cell) {
            $out[(string) $cell['key']] = (float) $cell[$field];
        }

        if (! $asShare) {
            return $out;
        }

        $total = array_sum($out);

        return $total == 0.0 ? array_map(fn () => 0.0, $out) : array_map(fn (float $value) => $value / $total, $out);
    }

    /**
     * @param  array<string, float>  $left
     * @param  array<string, float>  $right
     * @return array<string, float>
     */
    private function add(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            $left[$key] = ($left[$key] ?? 0.0) + $value;
        }

        return $left;
    }

    /**
     * @param  array<string, float>  $left
     * @param  array<string, float>  $right
     * @return array<string, float>
     */
    private function sub(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            $left[$key] = ($left[$key] ?? 0.0) - $value;
        }

        return array_map(fn (float $value) => round($value, 2), $left);
    }

    /**
     * O împărțire pe coloane, adusă la proporții; goală dacă n-are ce împărți.
     *
     * @param  array<string, float>  $cells
     * @return array<string, float>
     */
    private function normalise(array $cells): array
    {
        $total = array_sum($cells);

        // Împărțit la suma cu semn, nu la cea în valoare absolută: altfel o
        // linie negativă — venitul financiar net, de pildă — s-ar întoarce
        // pozitivă pe coloane, iar proporțiile n-ar mai face un întreg.
        if (abs($total) <= 0.005) {
            return [];
        }

        return array_map(fn (float $value) => $value / $total, $cells);
    }

    /**
     * Anul de bază, citit exact cum se citește planul.
     *
     * Coloana de alături nu e „anul de bază, pe tot anul”, ci anul de bază pe
     * aceeași perioadă, pe aceeași bază de chirii și pe aceeași recunoaștere
     * a venitului ca planul de lângă ea — altfel comparația ar minți.
     *
     * @param  list<int>  $wanted
     * @return array{totals: array<string, float>, rows: array<string, float>, shares: array<string, array<string, array<string, float>>>}|null
     */
    private function actuals(Plan $plan, array $wanted, string $period, string $basis, string $mode): ?array
    {
        $report = $this->pnl->cached($plan->company, $plan->base_year);

        if ($report === null || isset($report['error'])) {
            return null;
        }

        $slice = $this->pnl->slice($report, $wanted, $period, $basis, $mode);
        $margin = (float) $slice['revenue']['total']['margin'];
        $opex = (float) $slice['totals']['total'];
        $below = array_map(
            fn (string $bucket) => (float) ($slice['below'][$bucket]['total'] ?? 0),
            array_combine(['amortizare', 'financiar', 'impozit'], ['amortizare', 'financiar', 'impozit']),
        );

        $rows = [
            'revenue' => (float) $slice['revenue']['total']['net'],
            'margin' => $margin,
            'costs' => $opex,
            'ebitda' => $margin - $opex,
            'net' => $margin - $opex - array_sum($below),
        ];

        foreach ($below as $bucket => $value) {
            $rows['below:'.$bucket] = $value;
        }

        foreach (($slice['lines'] ?? []) as $line) {
            $rows['line:'.$line['saf']] = (float) $line['total'];
            $rows['group:'.$line['group']] = ($rows['group:'.$line['group']] ?? 0) + (float) $line['total'];
        }

        // Și cheia după care raportul a împărțit anul de bază pe coloane: o
        // cheltuială comună se plimbă în plan la fel cum s-a plimbat atunci,
        // nu după o regulă inventată acum.
        $shares = ['product' => [], 'channel' => []];

        foreach ([['product', 'by_product'], ['channel', 'by_channel']] as [$axis, $field]) {
            foreach (($slice['lines'] ?? []) as $line) {
                $shares[$axis]['line:'.$line['saf']] = $this->normalise($line[$field] ?? []);
            }

            foreach (['amortizare', 'financiar', 'impozit'] as $bucket) {
                $shares[$axis]['below:'.$bucket] = $this->normalise($slice['below'][$bucket][$field] ?? []);
            }

            $shares[$axis] = array_filter($shares[$axis], fn (array $cut) => $cut !== []);
        }

        return [
            'shares' => $shares,
            'totals' => [
                'net' => round($rows['revenue'], 2),
                'margin' => round($margin, 2),
                'opex' => round($opex, 2),
                'below' => round(array_sum($below), 2),
                'ebitda' => round($rows['ebitda'], 2),
                'net_profit' => round($rows['net'], 2),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<int, array<string, float>>  $months
     * @return array<string, float>
     */
    private function totals(array $months): array
    {
        $totals = ['net' => 0.0, 'margin' => 0.0, 'opex' => 0.0, 'below' => 0.0, 'ebitda' => 0.0, 'net_profit' => 0.0, 'pax' => 0.0, 'services' => 0.0, 'seats' => 0.0, 'empty_seats' => 0.0, 'empty_cost' => 0.0];

        foreach ($months as $cell) {
            foreach ($totals as $key => $value) {
                $totals[$key] = round($value + (float) ($cell[$key] ?? 0), 2);
            }
        }

        return $totals;
    }

    /**
     * Rândurile strânse pe cheie, cu lunile sub ele.
     *
     * @param  Collection<int, PlanLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function byKey(Collection $lines): array
    {
        return $lines
            ->groupBy('key')
            ->map(function (Collection $group, string $key) {
                $first = $group->first();
                $months = [];

                foreach ($group as $line) {
                    $months[(int) $line->month] = [
                        'id' => $line->id,
                        // Scrie pe card dacă luna e o estimare, nu o cifră trăită.
                        'note' => $line->note,
                        'unit_cost' => (float) $line->unit_cost,
                        'empty_seats' => $line->emptySeats(),
                        'empty_cost' => $line->emptyCost(),
                        'break_even' => $line->breakEven(),
                        'volume' => $line->kind === PlanLine::CHARTER ? round($line->pax(), 2) : (float) $line->volume,
                        'capacity' => (float) $line->capacity,
                        'load' => (float) $line->load,
                        'unit_net' => (float) $line->unit_net,
                        'unit_margin' => (float) $line->unit_margin,
                        'amount' => $line->kind === PlanLine::OPEX || $line->kind === PlanLine::BELOW
                            ? (float) $line->amount
                            : $line->net(),
                        'margin' => $line->margin(),
                        'manual' => (bool) $line->manual,
                    ];
                }

                ksort($months);

                return [
                    'key' => $key,
                    'kind' => $first->kind,
                    'unit' => $first->unit ?? PlanLine::PAX,
                    'label' => $first->label ?: $key,
                    'months' => $months,
                    'pax' => round($group->sum(fn (PlanLine $line) => $line->pax()), 2),
                    'seats' => round((float) $group->sum('capacity'), 2),
                    'net' => round($group->sum(fn (PlanLine $line) => $line->net()), 2),
                    'margin' => round($group->sum(fn (PlanLine $line) => $line->margin()), 2),
                    // Locurile rămase nevândute și cât ne costă: avionul se
                    // plătește întreg, vândut sau nu.
                    'empty_seats' => round($group->sum(fn (PlanLine $line) => $line->emptySeats()), 2),
                    'empty_cost' => round($group->sum(fn (PlanLine $line) => $line->emptyCost()), 2),
                    'unit_cost' => round((float) $group->avg('unit_cost'), 2),
                    'break_even' => $group->avg('unit_net') > 0
                        ? round((float) $group->avg('unit_cost') / (float) $group->avg('unit_net'), 4)
                        : null,
                    'amount' => round((float) $group->sum('amount'), 2),
                    'manual' => $group->contains('manual', true),
                ];
            })
            ->sortByDesc(fn (array $row) => abs($row['kind'] === PlanLine::OPEX || $row['kind'] === PlanLine::BELOW ? $row['amount'] : $row['net']))
            ->values()
            ->all();
    }
}
