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
    /** Linia de sub EBITDA care nu se poartă din istoric, ci se socotește. */
    public const TAX = 'impozit';

    public function __construct(
        private PnlReportService $pnl,
        private PlanBuilder $builder,
        private PlanSections $sections,
    ) {}

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
        // Pârghiile puse deja pe plan — cum se întâmplă când se citește o
        // variantă salvată — se iau de acolo; altfel, din baza de date.
        $lines = $plan->relationLoaded('lines') ? $plan->lines : $plan->lines()->get();
        $wanted = PnlReportService::months($period);

        // Anii dinainte, citiți cu aceeași măsură: aceeași perioadă, aceeași
        // bază de chirii, aceeași recunoaștere a venitului. Altfel coloanele
        // de alături ar fi alte rapoarte, nu termeni de comparație.
        $history = [];

        foreach (self::years($plan) as $year) {
            $read = $this->actuals($plan, $year, $wanted, $period, $basis, $mode);

            if ($read !== null) {
                $history[$year] = $read;
            }
        }

        $actual = $history[(int) $plan->base_year] ?? null;

        // Chiriile retratate ca în raport: ies din exploatare și se întorc ca
        // amortizare și dobândă, după contractele din registru.
        $leases = $basis === PnlReportService::BASIS_IFRS16 ? $this->leases($plan) : null;

        $months = [];
        $buckets = [];

        foreach (range(1, 12) as $month) {
            $months[$month] = ['net' => 0.0, 'margin' => 0.0, 'opex' => 0.0, 'below' => 0.0, 'pax' => 0.0, 'services' => 0.0, 'seats' => 0.0, 'empty_seats' => 0.0, 'empty_cost' => 0.0];
            $buckets[$month] = [];
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

                    foreach ($this->cut($mix[$key] ?? [], $line->b2b) as $channel => $cut) {
                        $this->pour($axes['channel'], (string) $channel, $to, $net * $cut, $margin * $cut, 0, 0);
                    }
                }

                continue;
            }

            if (! in_array($month, $wanted, true)) {
                continue;
            }

            match ($line->kind) {
                // Liniile care urmează venitul se socotesc după ce se
                // strânge venitul, mai jos: acum n-ar avea din ce.
                PlanLine::OPEX => $line->driver === PlanLine::PERCENT && ! $line->manual
                    ? null
                    : $months[$month]['opex'] += (float) $line->amount,
                // Magazinele se socotesc la urmă: bonusul lor se naște din
                // vânzările planificate, care tocmai s-au strâns mai sus.
                PlanLine::RETAIL => null,
                PlanLine::BELOW => $buckets[$month][$line->key] = ($buckets[$month][$line->key] ?? 0) + (float) $line->amount,
                default => null,
            };
        }

        // Cheltuielile care urmează venitul: acum se știe cât se vinde și
        // pe ce canale, deci comisionul bancar și promovarea își pot lua
        // procentul din venitul lor. Suma se scrie pe linie, ca tot ce
        // vine după ea — raportul, cardurile lunilor, exportul — s-o vadă
        // la fel.
        foreach ($lines as $line) {
            if ($line->kind !== PlanLine::OPEX || $line->driver !== PlanLine::PERCENT || $line->manual) {
                continue;
            }

            $month = (int) $line->month;

            if (! in_array($month, $wanted, true)) {
                $line->amount = 0.0;

                continue;
            }

            $revenue = [];

            foreach ($axes['channel'] as $channel => $cell) {
                $revenue[(string) $channel] = (float) ($cell['months'][$month] ?? 0);
            }

            $line->amount = $line->cost(PlanBuilder::revenueOf($revenue, (string) $line->driver_base));
            $months[$month]['opex'] += (float) $line->amount;
        }

        // Rețeaua de agenții: oameni × cost, plus bonusul din ce vinde
        // fiecare magazin. Se face acum, fiindcă bonusul are nevoie de
        // venitul planificat al canalului.
        $shops = $this->shops($lines, $axes['channel'], $wanted);

        foreach ($shops['months'] as $month => $amount) {
            $months[$month]['opex'] += $amount;
        }

        // Impozitul pe profit nu se poartă din anul trecut: se socotește din
        // cifrele planului, după regula fiscală.
        $buckets = $this->tax($plan, $lines, $months, $buckets, $leases);

        foreach ($buckets as $month => $cells) {
            $months[$month]['below'] = round(array_sum($cells), 2);
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
            'statement' => $this->statement($plan, $lines->whereIn('month', $wanted), $months, $leases, $actual, $axes, $this->keep($buckets, $wanted), $history),
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
            'categories' => $this->byKey($lines->whereIn('kind', [PlanLine::CATEGORY, PlanLine::CHARTER]), $mix),
            'charter' => $this->byKey($lines->where('kind', PlanLine::CHARTER), $mix),
            'opex' => $this->byKey($lines->where('kind', PlanLine::OPEX)),
            // Magazinele, cu oamenii și bonusurile lor.
            'retail' => $shops['rows'],
            'below' => $this->belowRows($lines->where('kind', PlanLine::BELOW), $buckets, $wanted),
            // Anii din care se judecă planul, de la cel mai vechi la cel mai
            // nou, cu lunile care le lipsesc spuse pe față.
            // Ce atârnă greu în plan: de unde vine venitul, cât de plin
            // trebuie să fie avionul și ce se întâmplă dacă nu e.
            'risk' => $this->risk($plan, $months, $buckets, $lines, $wanted),
            'years' => array_values(array_map(fn (int $year) => [
                'year' => $year,
                'built' => isset($history[$year]),
                // Lunile care n-au venit încă sunt o faptă de calendar: se
                // spun și când raportul anului nu e construit.
                'estimated' => PlanBuilder::openMonths($year),
                'pax' => $this->paxOf($plan, $year),
                'apart' => $history[$year]['apart'] ?? [],
                ...($history[$year]['totals'] ?? []),
            ], array_reverse(self::years($plan)))),
            'base' => [
                'year' => $plan->base_year,
                'built' => $actual !== null,
                // Lunile din anul de bază care n-au venit încă și intră în
                // coloană ca estimare, nu ca realizat.
                'estimated' => $actual['estimated'] ?? [],
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
     * Pe ce canale se împarte venitul unei linii.
     *
     * Amestecul vine din anul de bază, dar partea vândută prin agenții
     * partenere e o hotărâre a anului care vine: dacă omul a scris-o, ea se
     * ia ca atare, iar restul se împarte pe canalele directe în aceeași
     * proporție ca în istoric. O categorie care n-a avut niciun canal direct
     * își duce restul într-o coloană fără nume — mai bine nescris decât
     * inventat.
     *
     * @param  array<string, float>  $mix
     * @return array<string, float>
     */
    /**
     * Venitul unui rând, împărțit în cele două lumi.
     *
     * @param  Collection<int, PlanLine>  $group
     * @param  array<string, float>  $mix
     * @return array{b2b: float|null, net_b2b: float, net_b2c: float, net_other: float}
     */
    private function split(Collection $group, array $mix): array
    {
        $segments = (array) config('pnl.segments', []);
        $out = ['net_b2b' => 0.0, 'net_b2c' => 0.0, 'net_other' => 0.0];
        $net = 0.0;
        $theirs = 0.0;

        foreach ($group as $line) {
            if (! in_array($line->kind, [PlanLine::CATEGORY, PlanLine::CHARTER], true)) {
                continue;
            }

            $money = $line->net();
            $net += $money;

            foreach ($this->cut($mix, $line->b2b) as $channel => $share) {
                $where = 'net_other';

                foreach ($segments as $name => $block) {
                    if (in_array((string) $channel, (array) ($block['channels'] ?? []), true)) {
                        $where = 'net_'.$name;

                        break;
                    }
                }

                $out[$where] += $money * $share;

                if ($where === 'net_b2b') {
                    $theirs += $money * $share;
                }
            }
        }

        return [
            // Partea prin agenții partenere, așa cum iese din plan.
            'b2b' => $net > 0 ? round($theirs / $net, 4) : null,
            'net_b2b' => round($out['net_b2b'], 2),
            'net_b2c' => round($out['net_b2c'], 2),
            'net_other' => round($out['net_other'], 2),
        ];
    }

    /**
     * Cât din amestecul ăsta de canale trece prin agenții partenere.
     *
     * @param  array<string, float>  $mix
     */
    private function b2bOf(array $mix): ?float
    {
        if ($mix === []) {
            return null;
        }

        $keys = (array) config('pnl.segments.b2b.channels', ['b2b']);
        $all = array_sum($mix);

        if ($all <= 0) {
            return null;
        }

        $theirs = 0.0;

        foreach ($mix as $channel => $share) {
            if (in_array((string) $channel, $keys, true)) {
                $theirs += $share;
            }
        }

        return round($theirs / $all, 4);
    }

    private function cut(array $mix, ?float $b2b): array
    {
        if ($b2b === null) {
            return $mix === [] ? ['—' => 1.0] : $mix;
        }

        $b2b = max(0.0, min(1.0, $b2b));
        $keys = (array) config('pnl.segments.b2b.channels', ['b2b']);
        $rest = array_filter($mix, fn (string $channel) => ! in_array($channel, $keys, true), ARRAY_FILTER_USE_KEY);
        $all = array_sum($rest);

        $out = $b2b > 0 ? [$keys[0] => $b2b] : [];

        if ($all > 0) {
            foreach ($rest as $channel => $share) {
                $out[$channel] = $share / $all * (1 - $b2b);
            }
        } elseif ($b2b < 1) {
            $out['—'] = 1 - $b2b;
        }

        return $out === [] ? ['—' => 1.0] : $out;
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
    private function statement(Plan $plan, Collection $lines, array $months, ?array $leases = null, ?array $actual = null, array $axes = ['product' => [], 'channel' => []], array $buckets = [], array $history = []): array
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

        foreach ($this->byBucket($buckets) as $bucket => $cells) {

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

        return array_map(function (array $row) use ($actual, $history) {
            $years = [];
            $product = [];
            $channel = [];

            // Aceeași linie, în fiecare an din care se judecă planul.
            foreach ($history as $year => $read) {
                $years[$year] = isset($read['rows'][$row['key']]) ? round($read['rows'][$row['key']], 2) : null;
                $product[$year] = $read['columns']['product'][$row['key']] ?? [];
                $channel[$year] = $read['columns']['channel'][$row['key']] ?? [];
            }

            return [
                ...$row,
                'base' => isset($actual['rows'][$row['key']]) ? round($actual['rows'][$row['key']], 2) : null,
                'base_product' => $actual['columns']['product'][$row['key']] ?? [],
                'base_channel' => $actual['columns']['channel'][$row['key']] ?? [],
                'years' => $years,
                'years_product' => $product,
                'years_channel' => $channel,
            ];
        }, $rows);
    }

    /**
     * Rețeaua de agenții: ce costă, magazin cu magazin.
     *
     * Costul unui magazin nu se poartă dintr-o linie de cheltuială: e
     * numărul de oameni înmulțit cu cât costă unul, plus bonusul din ce
     * vinde magazinul. Vânzarea lui se ia din venitul planificat al
     * canalului retail, împărțit după cât a vândut fiecare în anul de bază.
     *
     * @param  Collection<int, PlanLine>  $lines
     * @param  array<string, array<string, mixed>>  $channels
     * @param  list<int>  $wanted
     * @return array{months: array<int, float>, rows: list<array<string, mixed>>}
     */
    private function shops(Collection $lines, array $channels, array $wanted): array
    {
        $shops = $lines->where('kind', PlanLine::RETAIL);

        if ($shops->isEmpty()) {
            return ['months' => array_fill_keys(range(1, 12), 0.0), 'rows' => []];
        }

        $channel = (string) config('pnl.plan_owners.costs.retail.channel', 'retail');
        $planned = $channels[$channel]['months'] ?? [];

        // Cât a vândut fiecare magazin în anul de bază, lună de lună: din
        // asta se împarte venitul planificat al canalului.
        $base = [];

        foreach ($shops as $line) {
            $base[(int) $line->month] = round(($base[(int) $line->month] ?? 0) + (float) $line->unit_net, 2);
        }

        $months = array_fill_keys(range(1, 12), 0.0);
        $rows = [];

        foreach ($shops->groupBy('key') as $key => $group) {
            $cells = [];
            $cost = 0.0;
            $sales = 0.0;

            foreach ($group as $line) {
                $month = (int) $line->month;
                $share = ($base[$month] ?? 0) > 0 ? (float) $line->unit_net / $base[$month] : 0.0;
                $mine = round((float) ($planned[$month] ?? 0) * $share, 2);
                $amount = $line->shop($mine);

                $cells[$month] = [
                    'id' => $line->id,
                    'fte' => (float) $line->volume,
                    'cost_per_fte' => (float) $line->unit_cost,
                    'bonus' => (float) $line->load,
                    'rent' => (float) $line->rent,
                    'sales' => $mine,
                    'was' => (float) $line->unit_net,
                    'amount' => $amount,
                    'manual' => (bool) $line->manual,
                    'note' => $line->note,
                ];

                if (in_array($month, $wanted, true)) {
                    $months[$month] += $amount;
                    $cost += $amount;
                    $sales += $mine;
                }
            }

            ksort($cells);

            $rows[] = [
                'key' => (string) $key,
                'section' => 'cost:retail',
                'kind' => PlanLine::RETAIL,
                'unit' => PlanLine::PAX,
                'label' => (string) $key,
                'months' => $cells,
                'fte' => round((float) $group->avg('volume'), 2),
                'cost_per_fte' => round((float) $group->avg('unit_cost'), 2),
                'bonus' => round((float) $group->avg('load'), 4),
                // Chiria se scrie pe lună, ca și costul unui om.
                'rent' => round((float) $group->avg('rent'), 2),
                'sales' => round($sales, 2),
                'amount' => round($cost, 2),
                'manual' => $group->contains('manual', true),
                // Cât din vânzările magazinului se duce în costul lui.
                'weight' => $sales > 0 ? round($cost / $sales * 100, 1) : null,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['sales'] <=> $a['sales']);

        return ['months' => array_map(fn (float $value) => round($value, 2), $months), 'rows' => $rows];
    }

    /**
     * Impozitul pe profit al planului, socotit ca la carte.
     *
     * Un impozit purtat din anul de bază nu spune nimic despre anul plănuit:
     * se plătește pe profitul care iese din plan, nu pe cel de anul trecut.
     * Regula e cea din lege — cel mai mare dintre impozitul pe profit și
     * impozitul minim pe cifra de afaceri — iar vărsămintele sunt
     * trimestriale, ca în registru.
     *
     * Ce scrie omul cu mâna pe linia de impozit rămâne scris: atunci
     * socoteala nu se mai face.
     *
     * @param  Collection<int, PlanLine>  $lines
     * @param  array<int, array<string, float>>  $months
     * @param  array<int, array<string, float>>  $buckets
     * @return array<int, array<string, float>>
     */
    private function tax(Plan $plan, Collection $lines, array $months, array $buckets, ?array $leases): array
    {
        $written = $lines->where('kind', PlanLine::BELOW)->where('key', self::TAX)->contains('manual', true);

        if ($written) {
            return $buckets;
        }

        $rate = (float) config('pnl.profit_tax.rate', 0.16);
        $minimum = (float) config('pnl.profit_tax.turnover_rate', 0.005);
        $quarters = (array) config('pnl.profit_tax.quarters', [3, 6, 9, 12]);

        $turnover = 0.0;
        $pretax = 0.0;

        foreach ($months as $month => $cell) {
            $turnover += (float) $cell['net'];

            // Profitul dinainte de impozit: marja, minus cheltuielile de
            // exploatare, minus amortizarea și rezultatul financiar —
            // impozitul însuși n-are ce căuta în baza lui.
            $under = 0.0;

            foreach ($buckets[$month] ?? [] as $bucket => $amount) {
                if ($bucket !== self::TAX) {
                    $under += (float) $amount;
                }
            }

            if ($leases !== null) {
                $under += (float) ($leases['below'][$month] ?? 0);
                $pretax += (float) ($leases['rent'][$month] ?? 0);
            }

            $pretax += (float) $cell['margin'] - (float) $cell['opex'] - $under;
        }

        $due = max($rate * max($pretax, 0.0), $minimum * max($turnover, 0.0));

        // Vărsat trimestrial, pe cât s-a câștigat în trimestrul ăla.
        $shares = [];

        foreach ($quarters as $quarter) {
            $from = $quarter - 2;
            $sum = 0.0;

            foreach (range(max(1, $from), $quarter) as $month) {
                $sum += max(0.0, (float) ($months[$month]['net'] ?? 0));
            }

            $shares[$quarter] = $sum;
        }

        $total = array_sum($shares);

        foreach ($buckets as $month => $cells) {
            unset($buckets[$month][self::TAX]);
        }

        foreach ($shares as $quarter => $share) {
            if (! isset($buckets[$quarter])) {
                continue;
            }

            $buckets[$quarter][self::TAX] = round($total > 0 ? $due * $share / $total : $due / count($shares), 2);
        }

        return $buckets;
    }

    /**
     * Cifrele de sub EBITDA, pe feluri, în ordinea raportului.
     *
     * @param  array<int, array<string, float>>  $buckets
     * @return array<string, array<int, float>>
     */
    private function byBucket(array $buckets): array
    {
        $out = [];

        foreach ($buckets as $month => $cells) {
            foreach ($cells as $bucket => $amount) {
                $out[$bucket][$month] = round((float) $amount, 2);
            }
        }

        uasort($out, fn (array $a, array $b) => abs(array_sum($b)) <=> abs(array_sum($a)));

        return $out;
    }

    /**
     * Numai lunile cerute.
     *
     * @param  array<int, array<string, float>>  $buckets
     * @param  list<int>  $wanted
     * @return array<int, array<string, float>>
     */
    private function keep(array $buckets, array $wanted): array
    {
        return array_intersect_key($buckets, array_flip($wanted));
    }

    /**
     * Rândurile de sub EBITDA pentru fila de configurare: sumele socotite,
     * nu cele scrise în plan, ca omul să vadă ce intră în profit.
     *
     * @param  Collection<int, PlanLine>  $lines
     * @param  array<int, array<string, float>>  $buckets
     * @param  list<int>  $wanted
     * @return list<array<string, mixed>>
     */
    private function belowRows(Collection $lines, array $buckets, array $wanted): array
    {
        $rows = $this->byKey($lines->whereIn('month', $wanted));
        $byBucket = $this->byBucket($this->keep($buckets, $wanted));

        foreach ($rows as $index => $row) {
            $cells = $byBucket[$row['key']] ?? [];

            foreach ($row['months'] as $month => $cell) {
                $rows[$index]['months'][$month]['amount'] = (float) ($cells[$month] ?? 0);
            }

            $rows[$index]['amount'] = round(array_sum($cells), 2);
            $rows[$index]['computed'] = $row['key'] === self::TAX && ! $row['manual'];
        }

        return $rows;
    }

    /**
     * Câți pasageri a dus compania într-un an, din istoricul strâns.
     */
    private function paxOf(Plan $plan, int $year): float
    {
        return round((float) ForecastHistory::query()
            ->where('company_id', $plan->company_id)
            ->where('year', $year)
            ->where('kind', PlanLine::CATEGORY)
            ->where('unit', PlanLine::PAX)
            ->sum('volume'), 0);
    }

    /**
     * Ce atârnă greu în planul ăsta.
     *
     * Un antet de buget nu e un loc pentru cinci cifre care se văd oricum în
     * tabel: e locul unde se spune din ce stă planul în picioare și ce-l
     * poate dărâma. Cât din venit vine din curse, cât de plin trebuie să fie
     * avionul ca să-și scoată banii, câte locuri rămân goale și cât costă
     * ele, și cât ia statul chiar dacă profitul e mic.
     *
     * @param  array<int, array<string, float>>  $months
     * @param  array<int, array<string, float>>  $buckets
     * @param  Collection<int, PlanLine>  $lines
     * @param  list<int>  $wanted
     * @return array<string, float|bool>
     */
    private function risk(Plan $plan, array $months, array $buckets, Collection $lines, array $wanted): array
    {
        $charter = $lines->where('kind', PlanLine::CHARTER)->whereIn('month', $wanted);
        $seats = (float) $charter->sum('capacity');
        $pax = (float) $charter->sum(fn (PlanLine $line) => $line->pax());
        $fromCharter = (float) $charter->sum(fn (PlanLine $line) => $line->net());

        $net = 0.0;
        $pretax = 0.0;
        $tax = 0.0;

        foreach ($wanted as $month) {
            $net += (float) ($months[$month]['net'] ?? 0);
            $tax += (float) ($buckets[$month][self::TAX] ?? 0);
            $under = 0.0;

            foreach ($buckets[$month] ?? [] as $bucket => $amount) {
                if ($bucket !== self::TAX) {
                    $under += (float) $amount;
                }
            }

            $pretax += (float) ($months[$month]['margin'] ?? 0) - (float) ($months[$month]['opex'] ?? 0) - $under;
        }

        // Cât venit aduce un punct de ocupare pe toată flota: cu el se
        // judecă dacă planul mai stă în picioare la o vară mai slabă.
        $perPoint = $pax > 0 ? $seats * 0.01 * ($fromCharter / $pax) : 0.0;

        return [
            'charter_share' => $net > 0 ? round($fromCharter / $net * 100, 1) : 0.0,
            'load' => $seats > 0 ? round($pax / $seats * 100, 1) : 0.0,
            'break_even' => round((float) $charter->avg(fn (PlanLine $line) => $line->breakEven() ?? 0) * 100, 1),
            'empty_seats' => round((float) $charter->sum(fn (PlanLine $line) => $line->emptySeats()), 0),
            'empty_cost' => round((float) $charter->sum(fn (PlanLine $line) => $line->emptyCost()), 2),
            'routes' => $charter->pluck('key')->unique()->count(),
            'tax' => round($tax, 2),
            // Impozitul minim pe cifra de afaceri se plătește și pe profit
            // mic: atunci statul ia mai mult decât 16% din profit.
            'tax_is_minimum' => $tax > (float) config('pnl.profit_tax.rate', 0.16) * max($pretax, 0.0) + 0.5,
            'per_point' => round($perPoint, 2),
        ];
    }

    /**
     * Anul în curs, așa cum se va încheia: realizat plus ce mai vine.
     *
     * Bugetul anului următor se face în octombrie–decembrie, când anul
     * curent are nouă-zece luni trăite și două-trei care vin. Dacă premisele
     * pleacă de la cele nouă luni, tot ce se plănuiește e strâmb de la
     * început. Aici stă anul întreg: ce s-a realizat, ce se mai așteaptă și
     * cât iese împreună — de aici pornește planul anului următor.
     *
     * @return array<string, mixed>|null
     */
    public function currentYear(Plan $plan, string $basis = PnlReportService::BASIS_RAS, string $mode = PnlReportService::MODE_OPERATIONAL): ?array
    {
        $year = (int) $plan->base_year;
        $open = PlanBuilder::openMonths($year);
        $lived = PlanBuilder::livedMonths($year);
        $all = range(1, 12);

        $total = $this->actuals($plan, $year, $all, 'ytd12', $basis, $mode);

        if ($total === null) {
            return null;
        }

        // Realizatul: numai lunile trăite, fără estimare peste ele.
        $done = $lived === []
            ? null
            : $this->actuals($plan, $year, $lived, 'ytd'.max($lived), $basis, $mode);

        $before = $this->actuals($plan, $year - 1, $all, 'ytd12', $basis, $mode);

        $rows = [];

        foreach ($this->shape() as $key => $label) {
            $sum = (float) ($total['rows'][$key] ?? 0);
            $actual = (float) ($done['rows'][$key] ?? 0);

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'actual' => round($actual, 2),
                'estimate' => round($sum - $actual, 2),
                'total' => round($sum, 2),
                'before' => $before === null ? null : round((float) ($before['rows'][$key] ?? 0), 2),
            ];
        }

        $categories = [];

        foreach (($total['columns']['product']['revenue'] ?? []) as $key => $sum) {
            $actual = (float) ($done['columns']['product']['revenue'][$key] ?? 0);

            $categories[] = [
                'key' => (string) $key,
                'actual' => round($actual, 2),
                'estimate' => round((float) $sum - $actual, 2),
                'total' => round((float) $sum, 2),
                'margin' => round((float) ($total['columns']['product']['margin'][$key] ?? 0), 2),
                'before' => $before === null ? null : round((float) ($before['columns']['product']['revenue'][$key] ?? 0), 2),
            ];
        }

        usort($categories, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return [
            'year' => $year,
            'lived' => $lived,
            'open' => $open,
            'basis' => $basis,
            'mode' => $mode,
            'rows' => $rows,
            'categories' => $categories,
            'totals' => [
                'actual' => $done['totals'] ?? [],
                'total' => $total['totals'],
                'before' => $before['totals'] ?? [],
            ],
        ];
    }

    /**
     * Rândurile mari ale raportului, în ordinea lui.
     *
     * @return array<string, string>
     */
    private function shape(): array
    {
        return [
            'revenue' => 'Venituri nete',
            'margin' => 'Marjă brută',
            'costs' => 'Cheltuieli totale operaționale',
            'ebitda' => 'EBITDA',
            'below:amortizare' => PnlReportService::BELOW_LABELS['amortizare'] ?? 'Amortizare',
            'below:financiar' => PnlReportService::BELOW_LABELS['financiar'] ?? 'Rezultat financiar',
            'below:impozit' => PnlReportService::BELOW_LABELS['impozit'] ?? 'Impozit pe profit',
            'net' => 'Profit net',
        ];
    }

    /**
     * Anii din care se judecă planul: anul de bază și cei dinaintea lui.
     *
     * @return list<int>
     */
    public static function years(Plan $plan): array
    {
        return array_map(
            fn (int $step) => (int) $plan->base_year - $step,
            range(0, PlanBuilder::YEARS - 1),
        );
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
        $open = PlanBuilder::openMonths($plan->base_year);

        $rows = ForecastHistory::query()
            ->where('company_id', $plan->company_id)
            ->where('year', $plan->base_year)
            ->where('kind', 'lag')
            ->when($open !== [], fn ($query) => $query->whereNotIn('month', $open))
            ->get();

        // Anul de bază n-a trăit toamna, iar toamna se vinde vara următoare:
        // fără lunile alea, tiparul n-ar ști nimic despre vânzarea timpurie.
        // Pentru ele se ia tiparul anului dinainte, care le-a trăit.
        if ($open !== []) {
            $rows = $rows->concat(
                ForecastHistory::query()
                    ->where('company_id', $plan->company_id)
                    ->where('year', $plan->base_year - 1)
                    ->where('kind', 'lag')
                    ->whereIn('month', $open)
                    ->get(),
            );
        }

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
            // Anul de bază se pune peste la urmă, dacă raportul lui e
            // construit; forma rândului rămâne aceeași oricum.
            'base' => null,
            'years' => [],
            'years_product' => [],
            'years_channel' => [],
            // Aceeași cifră, citită pe coloane: pe categorie de produs și pe
            // canal de vânzare.
            'product' => array_map(fn (float $value) => round($value, 2), $cut['product'] ?? []),
            'channel' => array_map(fn (float $value) => round($value, 2), $cut['channel'] ?? []),
            'base_product' => [],
            'base_channel' => [],
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
     * Anul de bază, desfăcut pe coloane, rând cu rând.
     *
     * Ca să se vadă evoluția de la stânga la dreapta pe fiecare categorie și
     * pe fiecare canal — nu doar pe totalul companiei.
     *
     * @param  array<string, mixed>  $slice
     * @return array<string, array<string, array<string, float>>>
     */
    private function baseColumns(array $slice, array $overlay = ['products' => []], array $open = []): array
    {
        $out = [];

        foreach ([['product', 'by_product'], ['channel', 'by_channel']] as [$axis, $field]) {
            $rows = [];

            foreach (($slice['revenue'][$field] ?? []) as $key => $money) {
                $rows['revenue'][(string) $key] = (float) $money['net'];
                $rows['margin'][(string) $key] = (float) $money['margin'];
            }

            // Lunile estimate ale anului de bază intră și ele pe coloane: pe
            // categorie se știe din ce categorie vin, pe canal se împart cum
            // s-a vândut categoria aia.
            if ($axis === 'product') {
                foreach ($overlay['products'] ?? [] as $key => $months) {
                    foreach ($months as $month => $cell) {
                        if (! in_array((int) $month, $open, true)) {
                            continue;
                        }

                        $rows['revenue'][(string) $key] = ($rows['revenue'][(string) $key] ?? 0) + $cell['net'];
                        $rows['margin'][(string) $key] = ($rows['margin'][(string) $key] ?? 0) + $cell['margin'];
                    }
                }
            }

            foreach (($slice['lines'] ?? []) as $line) {
                foreach (($line[$field] ?? []) as $key => $value) {
                    $rows['line:'.$line['saf']][(string) $key] = ($rows['line:'.$line['saf']][(string) $key] ?? 0) + (float) $value;
                    $rows['group:'.$line['group']][(string) $key] = ($rows['group:'.$line['group']][(string) $key] ?? 0) + (float) $value;
                    $rows['costs'][(string) $key] = ($rows['costs'][(string) $key] ?? 0) + (float) $value;
                }
            }

            $under = [];

            foreach (['amortizare', 'financiar', 'impozit'] as $bucket) {
                foreach (($slice['below'][$bucket][$field] ?? []) as $key => $value) {
                    $rows['below:'.$bucket][(string) $key] = ($rows['below:'.$bucket][(string) $key] ?? 0) + (float) $value;
                    $under[(string) $key] = ($under[(string) $key] ?? 0) + (float) $value;
                }
            }

            foreach ($rows['margin'] ?? [] as $key => $margin) {
                $costs = (float) ($rows['costs'][$key] ?? 0);
                $rows['ebitda'][$key] = $margin - $costs;
                $rows['net'][$key] = $margin - $costs - (float) ($under[$key] ?? 0);
            }

            $out[$axis] = array_map(
                fn (array $cells) => array_map(fn (float $value) => round($value, 2), $cells),
                $rows,
            );
        }

        return $out;
    }

    /**
     * Ce aduc bazele de rezervări care nu se văd în vederea operațională.
     *
     * Rezervările Vacanza stau în baza ei de eTrip. Contabilitatea le are —
     * de la 2026 sunt ale companiei — dar raportul operațional citește doar
     * baza Christian Tour. Pentru plan, anul de bază trebuie să fie întreg:
     * altfel ar porni de la patru cincimi din afacere și orice creștere ar
     * părea mai mare decât e.
     *
     * @param  list<int>  $lived
     * @return array{net: float, margin: float, bases: array<string, float>}
     */
    private function apart(Plan $plan, int $year, array $lived): array
    {
        $rows = ForecastHistory::query()
            ->where('company_id', $plan->company_id)
            ->where('year', $year)
            ->where('kind', 'extra')
            ->whereIn('month', $lived === [] ? [0] : $lived)
            ->get();

        $out = ['net' => 0.0, 'margin' => 0.0, 'bases' => []];

        foreach ($rows as $row) {
            $out['net'] += (float) $row->net;
            $out['margin'] += (float) $row->margin;
            $out['bases'][(string) $row->key] = round(($out['bases'][(string) $row->key] ?? 0) + (float) $row->net, 2);
        }

        $out['net'] = round($out['net'], 2);
        $out['margin'] = round($out['margin'], 2);

        return $out;
    }

    /**
     * Cât adună lunile estimate ale anului de bază.
     *
     * @param  array<string, mixed>  $overlay
     * @param  list<int>  $open
     * @return array{net: float, margin: float, opex: float, lines: array<string, float>, below: array<string, float>}
     */
    private function overlay(array $overlay, array $open): array
    {
        $out = ['net' => 0.0, 'margin' => 0.0, 'opex' => 0.0, 'lines' => [], 'below' => []];

        foreach ($open as $month) {
            $out['net'] += (float) ($overlay['revenue'][$month] ?? 0);
            $out['margin'] += (float) ($overlay['margin'][$month] ?? 0);
        }

        foreach ($overlay['lines'] ?? [] as $saf => $months) {
            foreach ($months as $month => $amount) {
                if (in_array((int) $month, $open, true)) {
                    $out['lines'][$saf] = ($out['lines'][$saf] ?? 0) + (float) $amount;
                    $out['opex'] += (float) $amount;
                }
            }
        }

        foreach ($overlay['below'] ?? [] as $bucket => $months) {
            foreach ($months as $month => $amount) {
                if (in_array((int) $month, $open, true)) {
                    $out['below'][$bucket] = ($out['below'][$bucket] ?? 0) + (float) $amount;
                }
            }
        }

        return $out;
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
     * @return array{totals: array<string, float>, rows: array<string, float>, shares: array<string, array<string, array<string, float>>>, columns: array<string, array<string, array<string, float>>>}|null
     */
    private function actuals(Plan $plan, int $year, array $wanted, string $period, string $basis, string $mode): ?array
    {
        $report = $this->pnl->cached($plan->company, $year);

        if ($report === null || isset($report['error'])) {
            return null;
        }

        // Anul de bază se citește pe lunile pe care le-a trăit; cele care
        // n-au venit încă vin din estimare, ca să nu se pună nouă luni
        // lângă douăsprezece și să iasă o creștere închipuită. Anii dinainte
        // sunt închiși: ei se citesc cum sunt.
        $overlay = $year === (int) $plan->base_year
            ? $this->builder->openOverlay($plan)
            : ['months' => [], 'revenue' => [], 'margin' => [], 'products' => [], 'lines' => [], 'groups' => [], 'below' => []];
        $lived = array_values(array_diff($wanted, $overlay['months']));
        $open = array_values(array_intersect($wanted, $overlay['months']));

        $slice = $this->pnl->slice($report, $lived, $period, $basis, $mode);
        $extra = $this->overlay($overlay, $open);

        // Vacanza are rezervările în baza ei. Registrul contabil le are —
        // deci vederea financiară e întreagă — dar vederea operațională
        // citește doar baza Christian Tour, așa că i le adăugăm aici.
        // Altfel anul de bază ar fi cu o cincime mai mic decât planul care
        // pornește din el.
        $apart = $mode === PnlReportService::MODE_OPERATIONAL
            ? $this->apart($plan, $year, $lived)
            : ['net' => 0.0, 'margin' => 0.0, 'bases' => []];

        $margin = (float) $slice['revenue']['total']['margin'] + $extra['margin'] + $apart['margin'];
        $opex = (float) $slice['totals']['total'] + $extra['opex'];
        $below = array_map(
            fn (string $bucket) => (float) ($slice['below'][$bucket]['total'] ?? 0) + (float) ($extra['below'][$bucket] ?? 0),
            array_combine(['amortizare', 'financiar', 'impozit'], ['amortizare', 'financiar', 'impozit']),
        );

        $rows = [
            'revenue' => (float) $slice['revenue']['total']['net'] + $extra['net'] + $apart['net'],
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

        foreach ($extra['lines'] as $saf => $amount) {
            $group = $overlay['groups'][$saf] ?? 'Alte cheltuieli';
            $rows['line:'.$saf] = ($rows['line:'.$saf] ?? 0) + $amount;
            $rows['group:'.$group] = ($rows['group:'.$group] ?? 0) + $amount;
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
            'columns' => $this->baseColumns($slice, $overlay, $open),
            'estimated' => $open,
            'apart' => $apart['bases'],
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
     * @param  array<string, array<string, float>>  $mix  amestecul de canale al anului de bază
     * @return list<array<string, mixed>>
     */
    private function byKey(Collection $lines, array $mix = []): array
    {
        return $lines
            ->groupBy('key')
            ->map(function (Collection $group, string $key) use ($mix) {
                $first = $group->first();
                $months = [];
                // Amestecul categoriei: de pe el se citește partea de B2B a
                // lunilor pe care nu le-a scris încă nimeni.
                $theirs = $mix[$first->kind === PlanLine::CHARTER ? PlanBuilder::CHARTER_CATEGORY : $key] ?? [];
                $share = $this->b2bOf($theirs);

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
                        // Cât din luna asta se vinde prin agenții partenere.
                        'b2b' => $line->b2b ?? $share,
                        'driver_rate' => $line->driver === PlanLine::PERCENT ? (float) $line->driver_rate : null,
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
                    // A cui e linia: fiecare își vede și scrie partea lui.
                    'section' => $this->sections->forLine($first->kind, $key, $first->label),
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
                    // Linia care urmează venitul: procentul ei și baza.
                    'driver' => $first->driver,
                    'driver_base' => $first->driver_base,
                    'driver_rate' => $first->driver === PlanLine::PERCENT
                        ? round((float) $group->avg('driver_rate'), 8)
                        : null,
                    // Cele două lumi în care se vinde categoria: prin
                    // agenții partenere și direct. Ce nu intră în niciuna —
                    // corporate, altele — se arată deoparte, nu se
                    // înghesuie unde nu-i e locul.
                    ...$this->split($group, $theirs),
                ];
            })
            ->sortByDesc(fn (array $row) => abs($row['kind'] === PlanLine::OPEX || $row['kind'] === PlanLine::BELOW ? $row['amount'] : $row['net']))
            ->values()
            ->all();
    }
}
