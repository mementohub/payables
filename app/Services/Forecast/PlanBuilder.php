<?php

namespace App\Services\Forecast;

use App\Models\BaseEstimate;
use App\Models\ForecastHistory;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Services\Reports\PnlReportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Umple un plan cu pârghiile lui, pornind din istoric.
 *
 * Pentru categoriile obișnuite, pârghia e pasagerul: câți au fost anul trecut
 * în luna aia, cât a lăsat fiecare și cât a rămas din el. Pentru charter,
 * pârghia e locul: câte s-au contractat pe destinația aia (pentru anul care
 * vine, cifra e deja în eTrip, nu e o presupunere), cu ce grad de ocupare și
 * cu ce venit de pasager. Cheltuielile pornesc de la ce s-a cheltuit, linie
 * cu linie, lună cu lună.
 *
 * Ce a scris omul rămâne: reconstrucția sare peste rândurile marcate „de
 * mână”, altfel planul s-ar șterge singur la fiecare apăsare de buton.
 */
class PlanBuilder
{
    /** Categoria de charter se plănuiește pe destinații, nu pe ea însăși. */
    public const CHARTER_CATEGORY = 'Charter';

    /** Rândurile venite din celălalt ERP își spun numele. */
    public const TINA_PREFIX = 'Tina · ';

    /** Câți ani în urmă se uită pornirea: anul de bază și doi dinaintea lui. */
    public const YEARS = 3;

    /**
     * Cât atârnă fiecare an în medie, de la cel mai nou la cel mai vechi.
     *
     * Anul trecut spune mai mult despre anul care vine decât cel de acum
     * trei ani, dar nici ăla nu e de aruncat: trei ani la rând arată dacă o
     * creștere e un drum sau o întâmplare.
     *
     * @var array<int, float>
     */
    public const WEIGHTS = [3.0, 2.0, 1.0];

    /** Fără istoric de unde judeca, nimic nu se mișcă de la anul de bază. */
    private const FLAT = ['volume' => 1.0, 'net' => 1.0, 'margin' => 1.0, 'years' => 1];

    /** Pornire: anul de bază dus mai departe cu trendul ultimilor ani. */
    public const START_TREND = 'trend';

    /** Pornire: anul de bază așa cum e, fără nicio creștere. */
    public const START_BASE = 'base';

    public function __construct(private PnlReportService $pnl) {}

    /**
     * Cât s-a scos din liniile de salarii pentru rețeaua de agenții, lună cu
     * lună: din suma asta se face bugetul magazinelor.
     *
     * @var array<int, float>
     */
    private array $taken = [];

    public function seed(Plan $plan, bool $keepManual = true): int
    {
        $written = 0;
        $this->taken = [];

        $written += $this->seedCategories($plan, $keepManual);
        $written += $this->seedCharter($plan, $keepManual);
        $written += $this->seedCosts($plan, $keepManual);
        $written += $this->seedRetail($plan, $keepManual);

        return $written;
    }

    /**
     * Categoriile, pe pasageri: volumul anului de bază, dus mai departe cu
     * trendul ultimilor trei ani.
     *
     * Pornirea nu mai e „cât a fost anul trecut”, fiindcă un an poate să fi
     * fost bun sau prost din întâmplare. Anul de bază rămâne ancora — e cea
     * mai proaspătă informație — dar cifra lui se duce înainte cu creșterea
     * medie a ultimilor trei ani, cântărită spre anii de curând.
     */
    private function seedCategories(Plan $plan, bool $keepManual): int
    {
        $rows = [];
        $base = $this->complete($plan, PlanLine::CATEGORY);
        $pace = $this->trend($plan, PlanLine::CATEGORY);

        foreach ($base as $row) {
            if ($row->key === self::CHARTER_CATEGORY) {
                continue;
            }

            $volume = (float) $row->volume;
            $net = (float) $row->net;
            $margin = (float) $row->margin;

            if ($volume <= 0) {
                continue;
            }

            $step = $pace[$row->key] ?? self::FLAT;
            $byHand = (bool) ($row->by_hand ?? false);

            $rows[] = [
                'kind' => PlanLine::CATEGORY,
                'unit' => $row->unit,
                'key' => $row->key,
                'label' => $row->key,
                'month' => $row->month,
                // Scris de mână pe anul de bază înseamnă scris: nu se mai
                // umflă cu niciun trend.
                'volume' => round($volume * ($byHand ? 1 : $step['volume']), 2),
                'unit_net' => round($net / $volume * ($byHand ? 1 : $step['net']), 2),
                'unit_margin' => round($margin / $volume * ($byHand ? 1 : $step['margin']), 2),
                'note' => $this->origin($plan, $row, $step),
            ];
        }

        return $this->write($plan, PlanLine::CATEGORY, $rows, $keepManual);
    }

    /**
     * De unde vine cifra scrisă în plan, spus pe șleau.
     *
     * Omul care deschide o lună trebuie să poată citi într-o propoziție de
     * ce scrie acolo ce scrie: din ce an s-a pornit, cu ce creștere și dacă
     * luna aia a fost trăită sau ghicită.
     *
     * @param  array{volume: float, net: float, margin: float, years: int}  $step
     */
    private function origin(Plan $plan, object $row, array $step): string
    {
        $pieces = [];

        if ($row->by_hand ?? false) {
            $pieces[] = sprintf('Luna %d e scrisă de mână, pe fila „Estimare %d”.', $plan->base_year, $plan->base_year);
        } elseif ($row->estimated ?? false) {
            $pieces[] = sprintf(
                'Luna n-avea încă cifre în %d: volumul e cel din %d, scalat cu cât a crescut anul, iar banii pe unitate sunt media lunilor trăite din %d.',
                $plan->base_year, $plan->base_year - 1, $plan->base_year,
            );
        } else {
            $pieces[] = sprintf('Pornit din %d, luna aceeași.', $plan->base_year);
        }

        if ($plan->start === self::START_BASE) {
            $pieces[] = 'Pornire „anul de bază ca atare”: fără nicio creștere peste el.';
        }

        if (! ($row->by_hand ?? false) && $step['years'] > 1) {
            $pieces[] = sprintf(
                'Dus mai departe cu trendul ultimilor %d ani: volum %s, venit pe unitate %s, marjă pe unitate %s.',
                $step['years'],
                $this->percent($step['volume']),
                $this->percent($step['net']),
                $this->percent($step['margin']),
            );
        }

        return implode(' ', $pieces);
    }

    private function percent(float $ratio): string
    {
        $change = ($ratio - 1) * 100;

        return ($change >= 0 ? '+' : '−').number_format(abs($change), 1, ',', '.').'%';
    }

    /**
     * Creșterea medie a ultimilor ani, rând cu rând.
     *
     * Se compară mere cu mere: numai lunile pe care anul de bază le-a trăit
     * deja intră în socoteală, altfel un an oprit în septembrie ar părea cu
     * un sfert mai mic decât cel dinainte. Raportul anului mai nou atârnă
     * mai greu, iar rezultatul se ține în frâu — nici o categorie nu se
     * dublează și nu se înjumătățește dintr-un trend.
     *
     * @return array<string, array{volume: float, net: float, margin: float, years: int}>
     */
    private function trend(Plan $plan, string $kind): array
    {
        // „Anul de bază ca atare” înseamnă fără nicio creștere: planul e
        // anul ăsta încă o dată, de la care omul urcă sau coboară singur.
        if ($plan->start === self::START_BASE) {
            return [];
        }

        $lived = $this->livedMonths($plan->base_year);
        $years = range($plan->base_year, $plan->base_year - self::YEARS + 1);

        $totals = [];

        foreach (ForecastHistory::query()
            ->where('company_id', $plan->company_id)
            ->whereIn('year', $years)
            ->where('kind', $kind)
            ->whereIn('month', $lived)
            ->get() as $row) {
            $cell = $totals[$row->key][$row->year] ?? ['volume' => 0.0, 'net' => 0.0, 'margin' => 0.0];

            $cell['volume'] += (float) $row->volume;
            $cell['net'] += (float) $row->net;
            $cell['margin'] += (float) $row->margin;
            $totals[$row->key][$row->year] = $cell;
        }

        $out = [];

        foreach ($totals as $key => $byYear) {
            $out[$key] = [
                'volume' => $this->pace($byYear, $years, fn (array $cell) => $cell['volume']),
                'net' => $this->pace($byYear, $years, fn (array $cell) => $cell['volume'] > 0 ? $cell['net'] / $cell['volume'] : 0.0),
                'margin' => $this->pace($byYear, $years, fn (array $cell) => $cell['volume'] > 0 ? $cell['margin'] / $cell['volume'] : 0.0),
                'years' => count(array_filter($byYear, fn (array $cell) => $cell['volume'] > 0 || abs($cell['net']) > 0)),
            ];
        }

        return $out;
    }

    /**
     * Media cântărită a creșterilor de la an la an.
     *
     * @param  array<int, array<string, float>>  $byYear
     * @param  list<int>  $years  de la cel mai nou la cel mai vechi
     * @param  callable(array<string, float>): float  $pick
     */
    private function pace(array $byYear, array $years, callable $pick): float
    {
        $sum = 0.0;
        $weight = 0.0;

        foreach ($years as $index => $year) {
            $before = $years[$index + 1] ?? null;

            if ($before === null || ! isset($byYear[$year], $byYear[$before])) {
                continue;
            }

            $now = $pick($byYear[$year]);
            $then = $pick($byYear[$before]);

            if ($then <= 0 || $now <= 0) {
                continue;
            }

            $w = self::WEIGHTS[$index] ?? 1.0;
            $sum += $w * ($now / $then);
            $weight += $w;
        }

        return $weight > 0 ? max(0.6, min(1.8, $sum / $weight)) : 1.0;
    }

    /**
     * Lunile pe care un an le-a trăit deja.
     *
     * Luna în curs nu se pune: pe 25 octombrie, octombrie e pe jumătate și
     * ar trage în jos orice comparație. De la ea încolo se estimează.
     *
     * @return list<int>
     */
    public static function livedMonths(int $year): array
    {
        if ($year < (int) now()->year) {
            return range(1, 12);
        }

        if ($year > (int) now()->year) {
            return [];
        }

        return (int) now()->month <= 1 ? [] : range(1, (int) now()->month - 1);
    }

    /**
     * Lunile anului care n-au venit încă — cele care se pot scrie de mână.
     *
     * @return list<int>
     */
    public static function openMonths(int $year): array
    {
        return array_values(array_diff(range(1, 12), self::livedMonths($year)));
    }

    /**
     * Charterul, pe destinații: locurile contractate pentru anul plănuit.
     *
     * Locurile pentru anul care vine sunt în eTrip, nu sunt o presupunere.
     * Gradul de ocupare se ia ca media cântărită a ultimilor trei ani ai
     * cursei — un grad de ocupare e un raport, nu se duce mai departe cu
     * trend — iar banii pe pasager pornesc din anul de bază și cresc cu
     * trendul categoriei Charter.
     */
    private function seedCharter(Plan $plan, bool $keepManual): int
    {
        $planned = $this->history($plan, $plan->year, PlanLine::CHARTER);
        $base = $this->complete($plan, PlanLine::CHARTER);
        $contracted = $planned->sum('capacity') > 0;

        // Gradul de ocupare și banii pe pasager se iau de pe cursa ei, nu
        // dintr-o medie pe toată flota: la Monastir omul lasă 2.834 de lei,
        // la Cluj–Antalya 4.091.
        $byKey = $base->groupBy('key');
        $seats = (float) $base->sum('capacity');
        $sold = (float) $base->sum('sold');
        $average = $seats > 0 ? $sold / $seats : 0.75;
        $money = $this->charterMoney($base);
        $loads = $this->loads($plan);
        $pace = $this->trend($plan, PlanLine::CATEGORY)[self::CHARTER_CATEGORY] ?? self::FLAT;

        // Pe un loc de charter nu zboară numai pasageri de „Charter”: pleacă
        // și Senior Voyage, și Circuite Culturale, și Exotice — fiecare
        // citit pe categoria lui în P&L. Cursa duce oamenii toți, dar în
        // plan poartă numai banii care se citesc pe categoria Charter;
        // restul stau la categoriile lor, plănuite separat. Altfel venitul
        // aceluiași dosar ar intra de două ori.
        $cut = $this->charterShare($plan, $base);

        $rows = [];

        foreach (($contracted ? $planned : $base) as $row) {
            $theirs = $byKey->get($row->key, collect());
            $month = $theirs->firstWhere('month', $row->month);
            $hand = $month !== null && ($month->by_hand ?? false);

            // Ocuparea: a cursei din anul de bază dacă a zburat atunci,
            // altfel media cântărită a anilor în care a zburat, altfel a
            // flotei.
            $load = $month !== null && $month->capacity > 0
                ? (float) $month->sold / (float) $month->capacity
                : ($loads[$row->key] ?? $average);

            $pax = (float) $theirs->sum('volume');
            $step = ($hand ? 1.0 : $pace['net']) * $cut['net'];
            $marginStep = ($hand ? 1.0 : $pace['net']) * $cut['margin'];

            $rows[] = [
                'kind' => PlanLine::CHARTER,
                'unit' => PlanLine::PAX,
                'key' => $row->key,
                'label' => $row->key,
                'month' => $row->month,
                'capacity' => $row->capacity,
                'load' => round(min(1.0, $load), 4),
                'unit_net' => $pax > 0 ? round((float) $theirs->sum('net') / $pax * $step, 2) : round($money['net'] * $step, 2),
                'unit_margin' => $pax > 0 ? round((float) $theirs->sum('margin') / $pax * $marginStep, 2) : round($money['margin'] * $marginStep, 2),
                // Cât plătim avionului pentru un loc: din el ies pragul și
                // costul locurilor goale.
                // Costul rămâne al locului întreg: avionul se plătește cu
                // tot cu pasagerii care se citesc la alte categorii.
                'unit_cost' => $pax > 0 ? round((float) $theirs->sum('cost') / $pax, 2) : $money['cost'],
                'note' => $this->charterNote($plan, $contracted, $hand, $loads[$row->key] ?? null, $pace, $cut['net']),
            ];
        }

        return $this->write($plan, PlanLine::CHARTER, $rows, $keepManual);
    }

    /**
     * @param  array{volume: float, net: float, margin: float, years: int}  $pace
     */
    private function charterNote(Plan $plan, bool $contracted, bool $hand, ?float $load, array $pace, float $cut): string
    {
        $pieces = [$contracted
            ? sprintf('Locuri contractate în eTrip pentru %d (nu o presupunere).', $plan->year)
            : sprintf('Locurile anului %d; pentru %d nu e contractat încă nimic.', $plan->base_year, $plan->year)];

        $pieces[] = $hand
            ? sprintf('Ocuparea și banii pe pasager sunt scrise de mână pe fila „Estimare %d”.', $plan->base_year)
            : sprintf('Ocupare din %d, bani pe pasager din %d.', $plan->base_year, $plan->base_year);

        if ($load !== null) {
            $pieces[] = sprintf('Media ultimilor ani pe cursa asta: %s ocupare.', number_format($load * 100, 1, ',', '.').'%');
        }

        if (! $hand && $pace['years'] > 1) {
            $pieces[] = sprintf('Banii pe pasager duși mai departe cu trendul categoriei Charter: %s.', $this->percent($pace['net']));
        }

        if ($cut < 0.999) {
            $pieces[] = sprintf(
                'Pe locurile cursei zboară și pasageri de Senior Voyage, Circuite și alte categorii: cursa poartă în plan numai %s din banii locului — partea citită pe categoria Charter — restul stă la categoriile lor.',
                number_format($cut * 100, 1, ',', '.').'%',
            );
        }

        return implode(' ', $pieces);
    }

    /**
     * Cât din banii unui loc de charter se citesc pe categoria „Charter”.
     *
     * Restul sunt ai categoriilor care zboară pe aceleași locuri — Senior
     * Voyage, Circuite Culturale, Circuite Exotice — și care se plănuiesc pe
     * rândurile lor. Proporția se ia din anul de bază și ține totalul
     * planului egal cu suma categoriilor, ca în P&L.
     *
     * @param  Collection<int, object>  $base  cursele anului de bază
     * @return array{net: float, margin: float}
     */
    private function charterShare(Plan $plan, Collection $base): array
    {
        $routes = (float) $base->sum('net');
        $routeMargin = (float) $base->sum('margin');

        $charter = ForecastHistory::query()
            ->where('company_id', $plan->company_id)
            ->where('year', $plan->base_year)
            ->where('kind', PlanLine::CATEGORY)
            ->where('key', self::CHARTER_CATEGORY)
            ->get();

        if ($routes <= 0 || $charter->isEmpty()) {
            return ['net' => 1.0, 'margin' => 1.0];
        }

        return [
            'net' => max(0.1, min(1.0, (float) $charter->sum('net') / $routes)),
            'margin' => $routeMargin != 0.0
                ? max(-2.0, min(2.0, (float) $charter->sum('margin') / $routeMargin))
                : 1.0,
        ];
    }

    /**
     * Gradul de ocupare al fiecărei curse, ca medie cântărită a ultimilor
     * trei ani: anul de curând atârnă mai greu, dar nici cei dinainte nu se
     * aruncă.
     *
     * @return array<string, float>
     */
    private function loads(Plan $plan): array
    {
        $years = range($plan->base_year, $plan->base_year - self::YEARS + 1);

        $rows = ForecastHistory::query()
            ->where('company_id', $plan->company_id)
            ->whereIn('year', $years)
            ->where('kind', PlanLine::CHARTER)
            ->get()
            ->groupBy('key');

        $out = [];

        foreach ($rows as $key => $group) {
            $sum = 0.0;
            $weight = 0.0;

            foreach ($years as $index => $year) {
                $theirs = $group->where('year', $year);
                $seats = (float) $theirs->sum('capacity');

                if ($seats <= 0) {
                    continue;
                }

                $w = self::WEIGHTS[$index] ?? 1.0;
                $sum += $w * ((float) $theirs->sum('sold') / $seats);
                $weight += $w;
            }

            if ($weight > 0) {
                $out[(string) $key] = round(min(1.0, $sum / $weight), 4);
            }
        }

        return $out;
    }

    /**
     * Anul întreg al unei cheltuieli: numai lunile care n-au venit încă se
     * umplu — găurile dintre lunile trăite sunt goale fiindcă așa au fost.
     *
     * Impozitul pe profit se varsă trimestrial: are mișcare în martie,
     * iunie, septembrie și decembrie, iar restul lunilor sunt goale cu
     * drept. Umplute cu media, trei vărsăminte de-ale lui 2026 se făceau
     * douăsprezece, iar impozitul planului sărea de la 3,8 la 15 milioane.
     *
     * Lunile care chiar n-au venit încă iau ce a scris omul. Dacă n-a scris,
     * o linie care curge lună de lună — salarii, chirii, amortizare — ia
     * ritmul ei din anul de bază, iar una care pică rar ia luna aceeași din
     * anul dinainte, ca să nu-și piardă tiparul trimestrial.
     *
     * @param  array<int, float>  $months  lunile trăite, cu mișcare
     * @param  array<int, float>  $hand  ce a scris omul pentru lunile care vin
     * @param  list<int>  $open  lunile care n-au venit încă
     * @param  array<int, float>  $before  aceleași luni, din anul dinainte
     * @return array<int, array{amount: float, source: string}>
     */
    private function fillYear(array $months, array $hand = [], array $open = [], array $before = []): array
    {
        if ($months === [] && $hand === []) {
            return [];
        }

        // Cât a crescut linia pe lunile trăite de amândoi anii.
        $common = array_intersect_key($before, $months);
        $growth = array_sum($common) != 0.0 ? array_sum($months) / array_sum($common) : 1.0;
        $growth = max(0.2, min(3.0, (float) $growth));

        // Câte luni din cele trăite au avut mișcare: de aici se vede dacă
        // linia curge lună de lună sau pică din când în când.
        $lived = max(1, 12 - count($open));
        $regular = count($months) >= (int) ceil(0.6 * $lived);
        $average = $months === [] ? 0.0 : round(array_sum($months) / count($months), 2);

        $out = [];

        foreach (range(1, 12) as $month) {
            if (! in_array($month, $open, true)) {
                $out[$month] = ['amount' => $months[$month] ?? 0.0, 'source' => 'actual'];

                continue;
            }

            if (isset($hand[$month])) {
                $out[$month] = ['amount' => round($hand[$month], 2), 'source' => 'hand'];

                continue;
            }

            // O linie care merge în fiecare lună — salarii, chirii,
            // amortizare — se duce mai departe cu ritmul ei din anul de
            // bază. Una care se mișcă rar — un vărsământ trimestrial, o
            // primă de an — se ia din luna aceeași a anului dinainte, altfel
            // i s-ar pierde tiparul.
            $out[$month] = match (true) {
                $regular => ['amount' => $average, 'source' => 'average'],
                isset($before[$month]) => ['amount' => round($before[$month] * $growth, 2), 'source' => 'before'],
                default => ['amount' => 0.0, 'source' => 'empty'],
            };
        }

        return $out;
    }

    /**
     * Anul de bază întregit: lunile care n-au venit încă.
     *
     * Un plan pornit din 2026 în octombrie ar lua nouă luni adevărate și
     * trei goale, iar anul ar ieși cu un sfert mai mic decât va fi. Lunile
     * care lipsesc se iau în ordinea asta: întâi ce a scris omul pe fila
     * „Estimare”, iar dacă n-a scris nimic, din anul dinainte înmulțit cu
     * cât a crescut anul până acum. Fiecare rând spune de unde vine.
     *
     * @return Collection<int, object>
     */
    private function complete(Plan $plan, string $kind): Collection
    {
        $open = self::openMonths($plan->base_year);
        $base = $this->history($plan, $plan->base_year, $kind)->reject(
            fn (ForecastHistory $row) => in_array((int) $row->month, $open, true),
        );

        if ($open === []) {
            return $base->values();
        }

        $before = $this->history($plan, $plan->base_year - 1, $kind);
        $lived = $base->pluck('month')->unique();

        // Cât a crescut anul pe lunile care există în amândouă — la volum,
        // fiindcă volumul e pârghia; banii pe unitate se iau din anul de
        // bază, nu din cel dinainte.
        $common = $before->whereIn('month', $lived->all());
        $growth = $common->sum('volume') > 0 ? $base->sum('volume') / $common->sum('volume') : 1.0;
        $growth = max(0.2, min(3.0, $growth));

        // Banii pe unitate ai fiecărui rând, din lunile trăite ale anului de
        // bază. Fără asta, o lună ciudată din anul dinainte — un octombrie
        // cu 8.545 de lei pe pasager la Cazare, de patru ori cât restul
        // anului — s-ar muta întreagă în estimare și ar umfla și planul, și
        // coloana anului de bază.
        $unit = $base->groupBy('key')->map(function (Collection $group) {
            $volume = (float) $group->sum('volume');
            $net = (float) $group->sum('net');

            return $volume > 0 ? [
                'net' => $net / $volume,
                'margin' => (float) $group->sum('margin') / $volume,
                'cost' => (float) $group->sum('cost') / $volume,
                // Cât din rândul ăsta zboară pe locuri de charter: lunile
                // estimate păstrează aceeași proporție.
                'on_charter' => $volume > 0 ? (float) $group->sum('charter_volume') / $volume : 0.0,
                'charter_net' => $net != 0.0 ? (float) $group->sum('charter_net') / $net : 0.0,
                'charter_margin' => (float) $group->sum('margin') != 0.0
                    ? (float) $group->sum('charter_margin') / (float) $group->sum('margin')
                    : 0.0,
            ] : null;
        });

        $byHand = BaseEstimate::query()
            ->where('company_id', $plan->company_id)
            ->where('year', $plan->base_year)
            ->where('kind', $kind)
            ->get()
            ->keyBy(fn (BaseEstimate $row) => $row->key.'|'.$row->month);

        $filled = $before
            ->whereIn('month', $open)
            ->map(function (ForecastHistory $row) use ($growth, $plan, $unit) {
                $copy = $row->replicate();
                $copy->year = $plan->base_year;
                // Volumul ține seama de lună — iulie nu e noiembrie — și se
                // scalează cu cât a crescut anul.
                $copy->volume = round($row->volume * $growth, 2);

                $theirs = $unit->get($row->key);

                if ($theirs === null) {
                    // Rândul n-a existat deloc în anul de bază: atunci chiar
                    // n-avem de unde lua banii pe unitate decât de la el.
                    $copy->net = round($row->net * $growth, 2);
                    $copy->margin = round($row->margin * $growth, 2);
                    $copy->cost = round($row->cost * $growth, 2);
                } else {
                    $copy->net = round($copy->volume * $theirs['net'], 2);
                    $copy->margin = round($copy->volume * $theirs['margin'], 2);
                    $copy->cost = round($copy->volume * $theirs['cost'], 2);
                    $copy->charter_volume = round($copy->volume * $theirs['on_charter'], 2);
                    $copy->charter_net = round($copy->net * $theirs['charter_net'], 2);
                    $copy->charter_margin = round($copy->margin * $theirs['charter_margin'], 2);
                }

                $copy->estimated = true;

                return $copy;
            })
            ->keyBy(fn (ForecastHistory $row) => $row->key.'|'.$row->month);

        // Ce a scris omul bate ce s-a ghicit.
        foreach ($byHand as $cell => $row) {
            $written = $filled->get($cell) ?? $this->blank($plan, $kind, $row);
            $written->volume = $row->pax();
            $written->net = $row->net();
            $written->margin = $row->margin();
            // Ce scrie omul pentru o categorie e al categoriei: cursele și
            // le scrie separat, pe rândurile lor.
            $written->charter_volume = 0;
            $written->charter_net = 0;
            $written->charter_margin = 0;
            $written->capacity = $row->capacity ?? $written->capacity;
            $written->sold = $row->pax();
            $written->cost = round($row->pax() * (float) $row->unit_cost, 2);
            $written->estimated = true;
            $written->by_hand = true;

            $filled->put($cell, $written);
        }

        return $base->concat($filled->values())->values();
    }

    /**
     * Anul de bază, pe lunile pe care nu le-a trăit încă.
     *
     * Coloana de alături din plan ar minți altfel: ar arăta nouă luni de
     * 2026 lângă douăsprezece luni de 2027 și ar face orice creștere să
     * pară uriașă. Așa, lunile care n-au venit se pun și acolo, cu aceleași
     * cifre din care pornește planul — scrise de mână dacă omul le-a scris,
     * socotite din anul dinainte dacă nu.
     *
     * @return array{months: list<int>, revenue: array<int, float>, margin: array<int, float>, products: array<string, array<int, array{net: float, margin: float}>>, lines: array<string, array<int, float>>, groups: array<string, string>, below: array<string, array<int, float>>}
     */
    public function openOverlay(Plan $plan): array
    {
        $open = self::openMonths($plan->base_year);
        $out = ['months' => $open, 'revenue' => [], 'margin' => [], 'products' => [], 'lines' => [], 'groups' => [], 'below' => []];

        if ($open === []) {
            return $out;
        }

        foreach ($this->complete($plan, PlanLine::CATEGORY) as $row) {
            $month = (int) $row->month;

            if (! in_array($month, $open, true)) {
                continue;
            }

            $out['revenue'][$month] = round(($out['revenue'][$month] ?? 0) + (float) $row->net, 2);
            $out['margin'][$month] = round(($out['margin'][$month] ?? 0) + (float) $row->margin, 2);

            $cell = $out['products'][$row->key][$month] ?? ['net' => 0.0, 'margin' => 0.0];
            $out['products'][$row->key][$month] = [
                'net' => round($cell['net'] + (float) $row->net, 2),
                'margin' => round($cell['margin'] + (float) $row->margin, 2),
            ];
        }

        $report = $this->pnl->cached($plan->company, $plan->base_year);

        if ($report === null || isset($report['error'])) {
            return $out;
        }

        $hand = $this->handCosts($plan);
        $before = $this->costMonths($plan, $plan->base_year - 1);

        foreach (($report['lines'] ?? []) as $line) {
            $saf = (string) $line['saf'];
            $months = [];

            foreach (($line['months'] ?? []) as $month => $cell) {
                if (! in_array((int) $month, $open, true) && abs((float) $cell['total']) >= 0.005) {
                    $months[(int) $month] = round((float) $cell['total'], 2);
                }
            }

            $out['groups'][$saf] = (string) $line['group'];

            foreach ($this->fillYear($months, $hand[PlanLine::OPEX][$saf] ?? [], $open, $before[PlanLine::OPEX][$saf] ?? []) as $month => $cell) {
                if (in_array($month, $open, true)) {
                    $out['lines'][$saf][$month] = $cell['amount'];
                }
            }
        }

        foreach (['amortizare', 'financiar', 'impozit'] as $bucket) {
            $months = [];

            foreach (($report['below'][$bucket]['months'] ?? []) as $month => $cell) {
                if (! in_array((int) $month, $open, true) && abs((float) $cell['total']) >= 0.005) {
                    $months[(int) $month] = round((float) $cell['total'], 2);
                }
            }

            foreach ($this->fillYear($months, $hand[PlanLine::BELOW][$bucket] ?? [], $open, $before[PlanLine::BELOW][$bucket] ?? []) as $month => $cell) {
                if (in_array($month, $open, true)) {
                    $out['below'][$bucket][$month] = $cell['amount'];
                }
            }
        }

        return $out;
    }

    /**
     * Pârghiile lunilor care n-au venit încă, așa cum stau acum.
     *
     * Pagina are nevoie să arate ce cifră folosește planul pentru octombrie,
     * noiembrie și decembrie din anul de bază, și dacă a scris-o omul sau a
     * socotit-o aplicația — altfel căsuțele ar fi goale și n-ar ști nimeni
     * de la ce pornește.
     *
     * @return array<string, array<string, mixed>>
     */
    public function openCells(Plan $plan): array
    {
        $open = self::openMonths($plan->base_year);

        if ($open === []) {
            return [];
        }

        $out = [];

        foreach ([PlanLine::CATEGORY, PlanLine::CHARTER] as $kind) {
            foreach ($this->complete($plan, $kind) as $row) {
                $month = (int) $row->month;

                if (! in_array($month, $open, true)) {
                    continue;
                }

                $volume = (float) $row->volume;
                $capacity = (float) $row->capacity;

                $out[$kind.'|'.$row->key.'|'.$month] = [
                    'volume' => round($volume, 2),
                    'capacity' => round($capacity, 2),
                    'load' => $capacity > 0 ? round($volume / $capacity, 4) : null,
                    'unit_net' => $volume > 0 ? round((float) $row->net / $volume, 2) : 0.0,
                    'unit_margin' => $volume > 0 ? round((float) $row->margin / $volume, 2) : 0.0,
                    'amount' => null,
                    'source' => ($row->by_hand ?? false) ? 'hand' : 'auto',
                ];
            }
        }

        $overlay = $this->openOverlay($plan);
        $hand = $this->handCosts($plan);

        foreach ([PlanLine::OPEX => $overlay['lines'], PlanLine::BELOW => $overlay['below']] as $kind => $lines) {
            foreach ($lines as $key => $months) {
                foreach ($months as $month => $amount) {
                    $out[$kind.'|'.$key.'|'.$month] = [
                        'volume' => null, 'capacity' => null, 'load' => null,
                        'unit_net' => null, 'unit_margin' => null,
                        'amount' => round((float) $amount, 2),
                        'source' => isset($hand[$kind][$key][$month]) ? 'hand' : 'auto',
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Un rând de istoric gol, pentru o lună scrisă de mână care n-are
     * pereche în anul dinainte — o cursă nouă, o categorie nouă.
     */
    private function blank(Plan $plan, string $kind, BaseEstimate $row): ForecastHistory
    {
        return new ForecastHistory([
            'company_id' => $plan->company_id,
            'year' => $plan->base_year,
            'kind' => $kind,
            'key' => $row->key,
            'unit' => $row->unit,
            'month' => $row->month,
            'volume' => 0, 'capacity' => $row->capacity, 'sold' => 0,
            'net' => 0, 'margin' => 0, 'cost' => 0,
        ]);
    }

    /**
     * Istoricul strâns al unui an, pe felul cerut.
     *
     * @return Collection<int, ForecastHistory>
     */
    private function history(Plan $plan, int $year, string $kind): Collection
    {
        return ForecastHistory::query()
            ->where('company_id', $plan->company_id)
            ->where('year', $year)
            ->where('kind', $kind)
            ->get();
    }

    /**
     * Cursa, așa cum o cunoaște omul: de unde pleacă și unde ajunge.
     *
     * @param  array{home: string, destination: string}  $row
     */
    private static function route(array $row): string
    {
        return $row['home'].'→'.$row['destination'];
    }

    /**
     * Media flotei, pentru cursele fără istoric propriu.
     *
     * @param  Collection<int, ForecastHistory>  $base
     * @return array{net: float, margin: float, cost: float}
     */
    private function charterMoney(Collection $base): array
    {
        $pax = (float) $base->sum('volume');

        return $pax > 0
            ? [
                'net' => round((float) $base->sum('net') / $pax, 2),
                'margin' => round((float) $base->sum('margin') / $pax, 2),
                'cost' => round((float) $base->sum('cost') / $pax, 2),
            ]
            : ['net' => 0.0, 'margin' => 0.0, 'cost' => 0.0];
    }

    /**
     * Cheltuielile și cifrele de sub EBITDA, din raportul anului de bază.
     *
     * Fiecare linie pornește din cât s-a cheltuit pe ea în anul de bază și
     * se duce mai departe cu cât a crescut linia aia în ultimii trei ani.
     * Lunile care n-au venit încă se scriu de mână pe fila „Estimare”, iar
     * dacă n-a scris nimeni nimic, iau media lunilor cu mișcare.
     */
    private function seedCosts(Plan $plan, bool $keepManual): int
    {
        $report = $this->pnl->cached($plan->company, $plan->base_year);

        if ($report === null || isset($report['error'])) {
            return 0;
        }

        $pace = $this->costTrend($plan);
        $open = self::openMonths($plan->base_year);
        $hand = $this->handCosts($plan);
        $before = $this->costMonths($plan, $plan->base_year - 1);
        $rows = [];

        foreach (($report['lines'] ?? []) as $line) {
            $saf = (string) $line['saf'];
            $months = [];

            foreach (($line['months'] ?? []) as $month => $cell) {
                if (in_array((int) $month, $open, true) || abs((float) $cell['total']) < 0.005) {
                    continue;
                }

                $months[(int) $month] = round((float) $cell['total'], 2);
            }

            $step = $pace[$saf] ?? ['ratio' => 1.0, 'years' => 1];
            $label = trim((string) $line['group'].' · '.$line['label']);

            // Liniile ținute de rețeaua de agenții se taie: partea
            // repartizată pe canalul retail se bugetează pe magazine, în
            // fila lor, iar aici rămân oamenii din centrală și din
            // celelalte canale. Altfel salariile ar intra de două ori.
            $apart = $this->retailShare($line);

            foreach ($months as $month => $amount) {
                $months[$month] = round($amount - ($apart[$month] ?? 0), 2);
            }

            // Partea rețelei pleacă de aici, dar trece prin aceleași mâini
            // ca restul liniei: lunile netrăite iau ritmul celor trăite, iar
            // totul se duce mai departe cu același trend. Altfel bugetul
            // magazinelor ar rămâne în urmă cu un an.
            foreach ($this->fillYear($apart, [], $open) as $month => $cell) {
                $this->taken[$month] = round(($this->taken[$month] ?? 0) + $cell['amount'] * $step['ratio'], 2);
            }

            foreach ($this->fillYear($months, $hand[PlanLine::OPEX][$saf] ?? [], $open, $before[PlanLine::OPEX][$saf] ?? []) as $month => $cell) {
                $rows[] = [
                    'kind' => PlanLine::OPEX,
                    'key' => $saf,
                    'label' => $label,
                    'month' => $month,
                    'amount' => round($cell['amount'] * ($cell['source'] === 'hand' ? 1 : $step['ratio']), 2),
                    'note' => $this->costNote($plan, $cell['source'], $step),
                ];
            }
        }

        $written = $this->write($plan, PlanLine::OPEX, $rows, $keepManual);
        $below = [];

        foreach (['amortizare', 'financiar', 'impozit'] as $bucket) {
            $months = [];

            foreach (($report['below'][$bucket]['months'] ?? []) as $month => $cell) {
                if (in_array((int) $month, $open, true) || abs((float) $cell['total']) < 0.005) {
                    continue;
                }

                $months[(int) $month] = round((float) $cell['total'], 2);
            }

            foreach ($this->fillYear($months, $hand[PlanLine::BELOW][$bucket] ?? [], $open, $before[PlanLine::BELOW][$bucket] ?? []) as $month => $cell) {
                $below[] = [
                    'kind' => PlanLine::BELOW,
                    'key' => $bucket,
                    'label' => PnlReportService::BELOW_LABELS[$bucket] ?? $bucket,
                    'month' => $month,
                    'amount' => $cell['amount'],
                    'note' => $this->costNote($plan, $cell['source'], ['ratio' => 1.0, 'years' => 1]),
                ];
            }
        }

        return $written + $this->write($plan, PlanLine::BELOW, $below, $keepManual);
    }

    /**
     * Cât din linia asta de cheltuială ține de rețeaua de agenții.
     *
     * Raportul repartizează salariile pe canale: din ele, partea de retail
     * se bugetează pe magazine — oameni și bonusuri — iar restul rămâne la
     * administrativ. Linia care nu e a rețelei nu se atinge.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, float>
     */
    private function retailShare(array $line): array
    {
        $block = (array) config('pnl.plan_owners.costs.retail', []);
        $channel = (string) ($block['channel'] ?? 'retail');

        if (! in_array((string) $line['saf'], (array) ($block['lines'] ?? []), true)) {
            return [];
        }

        $out = [];

        foreach (($line['months'] ?? []) as $month => $cell) {
            $out[(int) $month] = round((float) ($cell['channel'][$channel] ?? 0), 2);
        }

        return $out;
    }

    /**
     * Rețeaua de agenții, magazin cu magazin.
     *
     * Costul unui magazin nu se poartă dintr-o linie de cheltuială: se face
     * din oameni și din vânzări — câți lucrează acolo, cât costă unul pe
     * lună, ce bonus se plătește din ce vinde. Până când rețeaua scrie
     * oamenii, rămâne cifra din istoric, ca planul să nu pornească cu zero
     * salarii în agenții.
     */
    private function seedRetail(Plan $plan, bool $keepManual): int
    {
        $report = $this->pnl->cached($plan->company, $plan->base_year);

        if ($report === null || isset($report['error'])) {
            return 0;
        }

        $block = (array) config('pnl.plan_owners.costs.retail', []);
        $channel = (string) ($block['channel'] ?? 'retail');
        $open = self::openMonths($plan->base_year);

        // Ce s-a scos din liniile de salarii pentru rețea, deja dus mai
        // departe cu trendul și întregit pe an.
        $payroll = $this->taken;

        // Și cât a vândut fiecare magazin: din asta iese bonusul, și tot din
        // asta se împarte masa salarială pe magazine.
        $sales = [];

        foreach (($report['revenue']['months'] ?? []) as $month => $cell) {
            foreach (($cell['branch'] ?? []) as $key => $money) {
                if (! str_starts_with((string) $key, $channel.'|')) {
                    continue;
                }

                $shop = substr((string) $key, strlen($channel) + 1);
                $sales[$shop][(int) $month] = round((float) ($money['net'] ?? 0), 2);
            }
        }

        if ($sales === []) {
            return 0;
        }

        // Lunile pe care anul de bază nu le-a trăit n-au vânzări pe magazin:
        // li se dă ritmul lunilor trăite, altfel noiembrie și decembrie ar
        // rămâne fără niciun magazin, iar salariile lor s-ar pierde pe drum.
        foreach ($sales as $shop => $months) {
            $theirs = array_diff_key($months, array_flip($open));
            $rate = $theirs === [] ? 0.0 : round(array_sum($theirs) / count($theirs), 2);

            foreach ($open as $month) {
                $sales[$shop][$month] = $sales[$shop][$month] ?? $rate;
            }
        }

        $rows = [];

        foreach ($sales as $shop => $months) {
            foreach (range(1, 12) as $month) {
                $all = 0.0;

                foreach ($sales as $theirs) {
                    $all += (float) ($theirs[$month] ?? 0);
                }

                $share = $all > 0 ? (float) ($months[$month] ?? 0) / $all : 0.0;
                $mass = (float) ($payroll[$month] ?? 0);

                $rows[] = [
                    'kind' => PlanLine::RETAIL,
                    'unit' => PlanLine::PAX,
                    'key' => $shop,
                    'label' => $shop,
                    'month' => $month,
                    // Câți oameni are magazinul: îi scrie rețeaua.
                    'volume' => 0,
                    'unit_cost' => round((float) ($block['cost_per_fte'] ?? 6500), 2),
                    'load' => round((float) ($block['bonus'] ?? 0.01), 4),
                    // Venitul magazinului în anul de bază: reper, și cheia
                    // după care se împarte masa salarială și se judecă
                    // bonusul.
                    'unit_net' => round((float) ($months[$month] ?? 0), 2),
                    'amount' => round($mass * $share, 2),
                    'note' => sprintf(
                        'Din %s: %s lei vânzări în luna asta. Cât timp nu scrie nimeni câți oameni lucrează aici, costul e partea magazinului din salariile repartizate pe retail.',
                        $plan->base_year,
                        number_format((float) ($months[$month] ?? 0), 0, ',', '.'),
                    ),
                ];
            }
        }

        return $this->write($plan, PlanLine::RETAIL, $rows, $keepManual);
    }

    /**
     * @param  array{ratio: float, years: int}  $step
     */
    private function costNote(Plan $plan, string $source, array $step): ?string
    {
        $pieces = match ($source) {
            'hand' => [sprintf('Luna e scrisă de mână, pe cardurile lunilor din %d.', $plan->base_year)],
            'before' => [sprintf('Luna n-a venit încă în %d, iar linia se mișcă rar: luată din %d, luna aceeași, scalată cu cât a crescut linia.', $plan->base_year, $plan->base_year - 1)],
            'average' => [sprintf('Luna n-a venit încă în %d: pusă pe ritmul lunar al lui %d.', $plan->base_year, $plan->base_year)],
            'empty' => [sprintf('Luna n-a venit încă în %d și nici în %d n-a avut mișcare: lăsată goală.', $plan->base_year, $plan->base_year - 1)],
            default => [sprintf('Cât s-a cheltuit în %d, luna aceeași.', $plan->base_year)],
        };

        if ($source !== 'hand' && $step['years'] > 1 && abs($step['ratio'] - 1) > 0.001) {
            $pieces[] = sprintf('Dusă mai departe cu trendul ultimilor %d ani: %s.', $step['years'], $this->percent($step['ratio']));

            if ($step['ratio'] >= 1.199 || $step['ratio'] <= 0.801) {
                $pieces[] = 'Trendul adevărat era mai mare; o cheltuială nu se duce mai departe cu mai mult de un sfert, așa că s-a oprit aici. Scrie cifra cu mâna dacă știi altceva.';
            }
        }

        return implode(' ', $pieces);
    }

    /**
     * Cât a crescut fiecare linie de cheltuială în ultimii ani.
     *
     * Se compară numai lunile trăite de anul de bază, ca un an oprit în
     * septembrie să nu pară mai ieftin decât cel dinainte.
     *
     * @return array<string, array{ratio: float, years: int}>
     */
    private function costTrend(Plan $plan): array
    {
        $lived = self::livedMonths($plan->base_year);
        $years = range($plan->base_year, $plan->base_year - self::YEARS + 1);
        $totals = [];

        foreach ($years as $year) {
            $report = $this->pnl->cached($plan->company, $year);

            if ($report === null || isset($report['error'])) {
                continue;
            }

            foreach (($report['lines'] ?? []) as $line) {
                $sum = 0.0;

                foreach (($line['months'] ?? []) as $month => $cell) {
                    if ($lived !== [] && ! in_array((int) $month, $lived, true)) {
                        continue;
                    }

                    $sum += (float) $cell['total'];
                }

                $totals[(string) $line['saf']][$year] = ['volume' => $sum, 'net' => $sum, 'margin' => 0.0];
            }
        }

        $out = [];

        foreach ($totals as $saf => $byYear) {
            // O cheltuială nu se dublează fiindcă s-a dublat anul trecut:
            // salariile urmează inflația și oamenii, iar bugetul de
            // marketing e o hotărâre, nu un trend. Creșterea se ține între
            // −20% și +20%, iar cine știe altceva scrie cu mâna.
            $out[$saf] = [
                'ratio' => max(0.8, min(1.2, $this->pace($byYear, $years, fn (array $cell) => $cell['volume']))),
                'years' => count(array_filter($byYear, fn (array $cell) => abs($cell['volume']) > 0.005)),
            ];
        }

        return $out;
    }

    /**
     * Cheltuielile unui an, lună cu lună, din raportul lui.
     *
     * @return array<string, array<string, array<int, float>>>
     */
    private function costMonths(Plan $plan, int $year): array
    {
        $report = $this->pnl->cached($plan->company, $year);

        if ($report === null || isset($report['error'])) {
            return [];
        }

        $out = [];

        foreach (($report['lines'] ?? []) as $line) {
            foreach (($line['months'] ?? []) as $month => $cell) {
                $out[PlanLine::OPEX][(string) $line['saf']][(int) $month] = round((float) $cell['total'], 2);
            }
        }

        foreach (['amortizare', 'financiar', 'impozit'] as $bucket) {
            foreach (($report['below'][$bucket]['months'] ?? []) as $month => $cell) {
                $out[PlanLine::BELOW][$bucket][(int) $month] = round((float) $cell['total'], 2);
            }
        }

        return $out;
    }

    /**
     * Cheltuielile scrise de mână pentru lunile care n-au venit încă.
     *
     * @return array<string, array<string, array<int, float>>>
     */
    private function handCosts(Plan $plan): array
    {
        $out = [];

        foreach (BaseEstimate::query()
            ->where('company_id', $plan->company_id)
            ->where('year', $plan->base_year)
            ->whereIn('kind', [PlanLine::OPEX, PlanLine::BELOW])
            ->get() as $row) {
            $out[$row->kind][$row->key][(int) $row->month] = (float) $row->amount;
        }

        return $out;
    }

    /**
     * Scrie rândurile unui fel, fără să calce peste cele scrise de mână.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function write(Plan $plan, string $kind, array $rows, bool $keepManual): int
    {
        $manual = $keepManual
            ? PlanLine::query()->where('plan_id', $plan->id)->where('kind', $kind)->where('manual', true)
                ->get()->keyBy(fn (PlanLine $line) => $line->key.'|'.$line->month)
            : collect();

        return DB::transaction(function () use ($plan, $kind, $rows, $manual, $keepManual) {
            // Pornirea de la zero șterge tot rândul; cea obișnuită lasă pe
            // loc ce s-a scris de mână. Fără deosebirea asta, pornirea de la
            // zero lăsa linia scrisă de mână în tabel și încerca să scrie
            // alta peste ea, pe aceeași lună.
            PlanLine::query()
                ->where('plan_id', $plan->id)
                ->where('kind', $kind)
                ->when($keepManual, fn ($query) => $query->where('manual', false))
                ->delete();

            $written = 0;

            foreach (array_chunk($rows, 400) as $chunk) {
                $payload = [];

                foreach ($chunk as $row) {
                    if ($manual->has($row['key'].'|'.$row['month'])) {
                        continue;
                    }

                    // Un rând scos din plan cu mâna nu se întoarce de la
                    // sine: pornirea din istoric sare peste el.
                    if ($plan->isRemoved($kind, (string) $row['key'])) {
                        continue;
                    }

                    $payload[] = [
                        'plan_id' => $plan->id,
                        'unit' => PlanLine::PAX,
                        'manual' => false,
                        'note' => null,
                        'label' => null,
                        'volume' => null, 'capacity' => null, 'load' => null,
                        'unit_net' => null, 'unit_margin' => null, 'amount' => null,
                        'created_at' => now(), 'updated_at' => now(),
                        ...$row,
                    ];
                }

                if ($payload !== []) {
                    PlanLine::query()->insert($payload);
                    $written += count($payload);
                }
            }

            return $written;
        });
    }
}
