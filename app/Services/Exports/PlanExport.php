<?php

namespace App\Services\Exports;

use App\Models\Plan;
use App\Models\PlanLine;
use App\Models\PlanVersion;
use App\Services\Forecast\PlanBuilder;
use App\Services\Reports\PnlReportService;
use Illuminate\Support\Str;

/**
 * Planul anului, pregătit să plece din aplicație: în Excel, pe hârtie sau în
 * prezentare.
 *
 * Un buget scos din aplicație fără ipotezele lui e un tabel de cifre pe care
 * nu-l poate apăra nimeni în ședință. De-asta toate cele trei formate pleacă
 * din aceeași strângere de date: contul de profit și pierdere planificat,
 * pârghiile din care iese — pasageri, locuri, grad de ocupare, bani pe
 * pasager — și, la urmă, scris în cuvinte, pe ce s-a sprijinit fiecare
 * cifră.
 */
class PlanExport
{
    public const FORMATS = ['xlsx', 'pdf', 'pptx'];

    /** @var array<int, string> */
    public const MONTHS = [
        1 => 'Ian', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mai', 6 => 'Iun',
        7 => 'Iul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Noi', 12 => 'Dec',
    ];

    /** @var array<int, string> */
    private const LONG_MONTHS = [
        1 => 'ianuarie', 2 => 'februarie', 3 => 'martie', 4 => 'aprilie',
        5 => 'mai', 6 => 'iunie', 7 => 'iulie', 8 => 'august',
        9 => 'septembrie', 10 => 'octombrie', 11 => 'noiembrie', 12 => 'decembrie',
    ];

    /**
     * Numele fișierului, fără extensie: planul, varianta și ziua.
     */
    public function filename(Plan $plan, ?PlanVersion $version = null): string
    {
        return Str::slug(sprintf(
            'plan-%d-%s%s',
            $plan->year,
            $plan->name,
            $version === null ? '' : '-'.$version->label,
        ));
    }

    /**
     * Antetul care spune pe ce se citesc cifrele.
     *
     * @param  array<string, mixed>  $output
     */
    public function subtitle(Plan $plan, array $output, ?PlanVersion $version = null): string
    {
        $pieces = [
            sprintf('%s · pornit din %d', $plan->name, $plan->base_year),
            $this->period((string) $output['period']),
            $output['mode'] === PnlReportService::MODE_FINANCIAL
                ? 'venit la data plecării (financiar)'
                : 'venit la data rezervării (operațional)',
            $output['basis'] === PnlReportService::BASIS_IFRS16 ? 'chirii IFRS 16' : 'chirii statutar',
        ];

        if ($version !== null) {
            $pieces[] = sprintf(
                'varianta „%s”, salvată de %s la %s',
                $version->label,
                $version->createdBy?->name ?? 'cineva',
                $version->created_at?->format('d.m.Y H:i') ?? '',
            );
        }

        return implode(' · ', $pieces);
    }

    private function period(string $period): string
    {
        if (preg_match('/^mtd(\d{1,2})$/', $period, $matches) === 1) {
            return 'luna '.(self::LONG_MONTHS[(int) $matches[1]] ?? $period);
        }

        if (preg_match('/^ytd(\d{1,2})$/', $period, $matches) === 1) {
            return (int) $matches[1] === 12
                ? 'anul întreg'
                : 'cumulat până în '.(self::LONG_MONTHS[(int) $matches[1]] ?? $period);
        }

        return 'anul întreg';
    }

    /**
     * Planul strâns pe secțiuni, din care se fac și hârtia, și prezentarea.
     *
     * @param  array<string, mixed>  $output
     * @return array{title: string, subtitle: string, sections: list<array<string, mixed>>, assumptions: list<string>}
     */
    public function deck(Plan $plan, array $output, ?PlanVersion $version = null): array
    {
        return [
            'title' => sprintf('Plan %d · %s', $plan->year, $plan->company?->name ?? Brand::company()),
            'subtitle' => $this->subtitle($plan, $output, $version),
            'sections' => [
                $this->statement($plan, $output),
                $this->axis($plan, $output, 'product', 'Pe categorie de produs'),
                $this->axis($plan, $output, 'channel', 'Pe canal de vânzare'),
                $this->charter($output),
                $this->levers($output),
                $this->costs($output),
            ],
            'assumptions' => $this->assumptions($plan, $output, $version),
        ];
    }

    /**
     * Contul de profit și pierdere planificat, rând cu rând.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function statement(Plan $plan, array $output): array
    {
        $rows = [];

        foreach ($output['statement'] as $row) {
            $rows[] = [
                'cells' => [
                    $row['label'],
                    $row['base'],
                    $row['total'],
                    $this->growth($row['total'], $row['base']),
                ],
                'style' => match ($row['kind']) {
                    'result' => 'total',
                    'strong' => 'section',
                    'group' => 'group',
                    default => 'normal',
                },
                'indent' => $row['kind'] === 'line',
            ];
        }

        return [
            'title' => 'Contul de profit și pierdere planificat',
            'columns' => [
                ['label' => 'Linie', 'width' => 54],
                ['label' => $plan->base_year.' (lei)', 'width' => 16, 'align' => 'right'],
                ['label' => 'Plan '.$plan->year.' (lei)', 'width' => 16, 'align' => 'right'],
                ['label' => 'Δ (%)', 'width' => 10, 'align' => 'right'],
            ],
            'rows' => $rows,
            'note' => sprintf(
                'Aceleași rânduri ca în raportul P&L. Coloana %d e anul de bază citit pe aceeași perioadă și pe aceeași bază.',
                $plan->base_year,
            ),
        ];
    }

    /**
     * Venitul planificat pe coloane: categorii sau canale.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function axis(Plan $plan, array $output, string $axis, string $title): array
    {
        $labels = $axis === 'channel' ? (array) config('pnl.channels', []) : [];
        $rows = [];

        $find = function (string $key) use ($output): array {
            foreach ($output['statement'] as $row) {
                if ($row['key'] === $key) {
                    return $row;
                }
            }

            return ['product' => [], 'channel' => [], 'base_product' => [], 'base_channel' => []];
        };

        $revenue = $find('revenue');
        $margin = $find('margin');
        $profit = $find('net');
        $base = $axis === 'product' ? 'base_product' : 'base_channel';

        foreach ($output['columns'][$axis] as $column) {
            $key = (string) $column['key'];
            $net = (float) ($revenue[$axis][$key] ?? 0);
            $was = $revenue[$base][$key] ?? null;

            $rows[] = ['cells' => [
                $labels[$key] ?? ($key === '- -' ? 'Fără categorie' : $key),
                $axis === 'product' ? round((float) $column['pax'] + (float) $column['services'], 0) : null,
                $was,
                $net,
                $this->growth($net, $was),
                (float) ($margin[$axis][$key] ?? 0),
                $net > 0 ? round(((float) ($margin[$axis][$key] ?? 0)) / $net * 100, 1).'%' : null,
                (float) ($profit[$axis][$key] ?? 0),
            ]];
        }

        $rows[] = [
            'cells' => [
                'Total',
                $axis === 'product' ? round((float) $output['totals']['pax'] + (float) $output['totals']['services'], 0) : null,
                $output['base']['net'] ?? null,
                $output['totals']['net'],
                $this->growth((float) $output['totals']['net'], $output['base']['net'] ?? null),
                $output['totals']['margin'],
                $output['totals']['net'] > 0
                    ? round((float) $output['totals']['margin'] / (float) $output['totals']['net'] * 100, 1).'%'
                    : null,
                $output['totals']['net_profit'],
            ],
            'style' => 'total',
        ];

        return [
            'title' => $title,
            'columns' => [
                ['label' => $axis === 'product' ? 'Categorie de produs' : 'Canal de vânzare', 'width' => 34],
                ['label' => 'Pax / servicii (nr.)', 'width' => 14, 'align' => 'right'],
                ['label' => 'Venit '.$plan->base_year.' (lei)', 'width' => 16, 'align' => 'right'],
                ['label' => 'Venit '.$plan->year.' (lei)', 'width' => 16, 'align' => 'right'],
                ['label' => 'Δ (%)', 'width' => 10, 'align' => 'right'],
                ['label' => 'Marjă '.$plan->year.' (lei)', 'width' => 16, 'align' => 'right'],
                ['label' => 'Marjă (% din venit)', 'width' => 10, 'align' => 'right'],
                ['label' => 'Profit net (lei)', 'width' => 16, 'align' => 'right'],
            ],
            'rows' => $rows,
            'note' => $axis === 'product'
                ? 'Cheltuielile se împart pe categorii după cum s-au împărțit aceleași linii în anul de bază; liniile noi, după venit.'
                : 'Amestecul de canale al fiecărei categorii e cel din anul de bază, până se schimbă cu mâna.',
        ];
    }

    /**
     * Cursele de charter: de aici pleacă jumătate din plan.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function charter(array $output): array
    {
        $rows = [];

        foreach ($output['charter'] as $route) {
            $rows[] = ['cells' => [
                $route['key'],
                $route['seats'],
                $route['seats'] > 0 ? round((float) $route['pax'] / (float) $route['seats'] * 100, 1).'%' : null,
                $route['pax'],
                $route['months'] === [] ? null : round((float) $route['net'] / max(1.0, (float) $route['pax']), 0),
                $route['unit_cost'],
                $route['break_even'] === null ? null : round((float) $route['break_even'] * 100, 1).'%',
                $route['net'],
                $route['margin'],
                $route['empty_seats'],
                $route['empty_cost'],
            ]];
        }

        return [
            'title' => 'Charter · cursele contractate',
            'columns' => [
                ['label' => 'Cursă', 'width' => 22],
                ['label' => 'Locuri (nr.)', 'width' => 12, 'align' => 'right'],
                ['label' => 'Ocupare (%)', 'width' => 10, 'align' => 'right'],
                ['label' => 'Pasageri (nr.)', 'width' => 12, 'align' => 'right'],
                ['label' => 'Venit/pax (lei)', 'width' => 12, 'align' => 'right'],
                ['label' => 'Cost/loc (lei)', 'width' => 12, 'align' => 'right'],
                ['label' => 'Prag (%)', 'width' => 10, 'align' => 'right'],
                ['label' => 'Venit (lei)', 'width' => 16, 'align' => 'right'],
                ['label' => 'Marjă (lei)', 'width' => 14, 'align' => 'right'],
                ['label' => 'Locuri goale (nr.)', 'width' => 12, 'align' => 'right'],
                ['label' => 'Cost locuri goale (lei)', 'width' => 14, 'align' => 'right'],
            ],
            'rows' => $rows,
            'note' => 'Locurile sunt cele contractate în eTrip pentru anul plănuit; costul de loc e cel al cursei, din eTrip. Pragul e gradul de ocupare de la care cursa își acoperă zborul: cost pe loc împărțit la venitul pe pasager.',
        ];
    }

    /**
     * Pârghiile categoriilor: din ce se face venitul.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function levers(array $output): array
    {
        $rows = [];

        foreach ($output['categories'] as $row) {
            if ($row['kind'] !== PlanLine::CATEGORY) {
                continue;
            }

            $rows[] = ['cells' => [
                $row['key'],
                $row['unit'] === PlanLine::SERVICE ? 'servicii' : 'pasageri',
                $row['pax'],
                $row['pax'] > 0 ? round((float) $row['net'] / (float) $row['pax'], 0) : null,
                $row['pax'] > 0 ? round((float) $row['margin'] / (float) $row['pax'], 0) : null,
                $row['net'],
                $row['margin'],
                $row['manual'] ? 'da' : '',
            ]];
        }

        return [
            'title' => 'Categorii · pârghiile',
            'columns' => [
                ['label' => 'Categorie', 'width' => 30],
                ['label' => 'Se numără în', 'width' => 14],
                ['label' => 'Volum (nr.)', 'width' => 12, 'align' => 'right'],
                ['label' => 'Venit/unitate (lei)', 'width' => 14, 'align' => 'right'],
                ['label' => 'Marjă/unitate (lei)', 'width' => 14, 'align' => 'right'],
                ['label' => 'Venit (lei)', 'width' => 16, 'align' => 'right'],
                ['label' => 'Marjă (lei)', 'width' => 16, 'align' => 'right'],
                ['label' => 'Scris de mână', 'width' => 12],
            ],
            'rows' => $rows,
            'note' => 'Venitul unei categorii e volumul înmulțit cu venitul pe unitate. „Scris de mână” înseamnă că cifra a fost schimbată de om și nu se mai calcă la pornirea din istoric.',
        ];
    }

    /**
     * Cheltuielile planificate, pe linii.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function costs(array $output): array
    {
        $net = (float) $output['totals']['net'];
        $rows = [];

        foreach ($output['opex'] as $row) {
            $rows[] = ['cells' => [
                $row['label'] ?: $row['key'],
                $row['amount'],
                $net > 0 ? round((float) $row['amount'] / $net * 100, 2).'%' : null,
                $row['manual'] ? 'da' : '',
            ]];
        }

        $rows[] = ['cells' => ['Cheltuieli totale operaționale', $output['totals']['opex'], null, ''], 'style' => 'total'];

        foreach ($output['below'] as $row) {
            $rows[] = ['cells' => [
                PnlReportService::BELOW_LABELS[$row['key']] ?? ($row['label'] ?: $row['key']),
                $row['amount'],
                null,
                $row['manual'] ? 'da' : '',
            ]];
        }

        return [
            'title' => 'Cheltuieli planificate',
            'columns' => [
                ['label' => 'Linie', 'width' => 44],
                ['label' => 'Sumă (lei)', 'width' => 16, 'align' => 'right'],
                ['label' => '% din venit', 'width' => 12, 'align' => 'right'],
                ['label' => 'Scris de mână', 'width' => 12],
            ],
            'rows' => $rows,
            'note' => 'Cheltuielile pornesc din ce s-a cheltuit în anul de bază, linie cu linie, și se schimbă cu mâna acolo unde se știe altceva.',
        ];
    }

    /**
     * Ipotezele, scrise în cuvinte.
     *
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    public function assumptions(Plan $plan, array $output, ?PlanVersion $version = null): array
    {
        $out = [];

        if ($version?->note) {
            $out[] = $version->note;
        }

        if ($plan->note) {
            $out[] = $plan->note;
        }

        $out[] = 'Toate sumele sunt în lei. Ce s-a vândut în altă monedă e adus la lei cu cursul zilei rezervării. Volumele sunt număr de pasageri, de servicii facturate sau de locuri de avion, iar gradul de ocupare și pragul sunt procente.';

        $out[] = sprintf(
            'Planul pornește din %d, din P&L-ul construit al aplicației — aceleași cifre ca raportul, nu altele asemănătoare.',
            $plan->base_year,
        );

        $out[] = sprintf(
            'Pornirea implicită nu e „cât a fost anul trecut”: fiecare pârghie pleacă din %d și se duce mai departe cu creșterea medie a ultimilor %d ani, cântărită spre anii de curând (%s). Gradul de ocupare al curselor e media cântărită a acelorași ani, fiindcă un procent nu se duce mai departe cu un trend. Nota fiecărei luni spune de unde vine cifra ei.',
            $plan->base_year,
            PlanBuilder::YEARS,
            implode(', ', array_map(fn (float $weight) => rtrim(rtrim(number_format($weight, 0), '0'), '.'), PlanBuilder::WEIGHTS)),
        );

        $out[] = $output['mode'] === PnlReportService::MODE_FINANCIAL
            ? sprintf('Venitul e recunoscut la data plecării. Ce se plănuiește pe luna rezervării se duce înainte, în lunile plecării, după tiparul din %d.', $plan->base_year)
            : sprintf('Venitul e recunoscut la data rezervării. Cursele de charter, plănuite pe luna zborului, se întorc în lunile în care se vând locurile, după tiparul din %d.', $plan->base_year);

        if ($output['shifted'] === false) {
            $out[] = 'Tiparul rezervare → plecare nu e încă strâns pentru anul de bază, așa că cele două vederi arată la fel.';
        }

        $out[] = 'Categoriile se plănuiesc pe pasageri: volum × venit pe pasager, cu marja pe pasager alături. Corporate-ul din Tina se numără pe servicii facturate, nu pe pasageri.';
        $out[] = 'Charterul se plănuiește pe locurile contractate în eTrip × gradul de ocupare, cursă cu cursă. Avionul se plătește întreg, așa că locurile goale au costul lor scris alături.';

        if ($output['basis'] === PnlReportService::BASIS_IFRS16 && $output['leases'] !== null) {
            $out[] = sprintf(
                'Chiriile sunt retratate IFRS 16 după %d contracte din registru: ies din cheltuielile de exploatare (%s lei) și se întorc sub EBITDA ca amortizare (%s) și dobândă (%s).',
                $output['leases']['leases'],
                number_format((float) $output['leases']['rent'], 0, ',', '.'),
                number_format((float) $output['leases']['depreciation'], 0, ',', '.'),
                number_format((float) $output['leases']['interest'], 0, ',', '.'),
            );
        } else {
            $out[] = 'Chiriile sunt lăsate ca în situațiile statutare, cheltuială de exploatare lună de lună.';
        }

        $estimated = 0;
        $manual = 0;

        foreach ([...$output['categories'], ...$output['opex'], ...$output['below']] as $row) {
            foreach ($row['months'] as $month) {
                if (str_contains((string) ($month['note'] ?? ''), 'estimat')) {
                    $estimated++;
                }

                if ($month['manual'] ?? false) {
                    $manual++;
                }
            }
        }

        $open = PlanBuilder::openMonths($plan->base_year);

        if ($open !== []) {
            $out[] = sprintf(
                'Anul de bază %d n-a trăit încă lunile %s: și în coloana lui, și în pornirea planului, ele sunt estimate — scrise de mână pe fila „Configurare”, dacă s-au scris, altfel socotite din %d crescut cu cât a crescut anul. Fără ele s-ar pune nouă luni lângă douăsprezece și orice creștere ar părea mai mare decât e.',
                $plan->base_year,
                implode(', ', array_map(fn (int $month) => self::MONTHS[$month] ?? $month, $open)),
                $plan->base_year - 1,
            );
        }

        if ($estimated > 0) {
            $out[] = sprintf(
                '%d dintre pârghiile lunare pornesc din luni pe care anul de bază nu le trăise încă: sunt estimate din anul dinainte, crescute cu cât a crescut anul, și sunt însemnate „estimat” în foaia de pârghii.',
                $estimated,
            );
        }

        $out[] = $manual > 0
            ? sprintf('%d pârghii au fost schimbate cu mâna; restul vin din istoric. Ce e scris de mână nu se mai calcă la împrospătare.', $manual)
            : 'Nicio pârghie n-a fost schimbată cu mâna: planul e, deocamdată, istoricul dus mai departe.';

        $out[] = sprintf(
            'Cifrele sunt citite %s, pe %s.',
            $this->period((string) $output['period']),
            $output['basis'] === PnlReportService::BASIS_IFRS16 ? 'baza IFRS 16' : 'baza statutară',
        );

        return $out;
    }

    /**
     * Planul ca registru Excel: fiecare foaie cu pârghiile ei, la zi.
     *
     * Hârtia se citește, Excel-ul se frământă — de-asta aici coboară tot ce
     * s-a plănuit, lună cu lună, cu grafice pe foile de sinteză.
     *
     * @param  array<string, mixed>  $output
     * @return list<array<string, mixed>>
     */
    public function sheets(Plan $plan, array $output, ?PlanVersion $version = null): array
    {
        $deck = $this->deck($plan, $output, $version);
        $labels = (array) config('pnl.channels', []);

        $statement = [];

        foreach ($output['statement'] as $row) {
            $statement[] = [
                $row['label'],
                $row['base'],
                $row['total'],
                $this->growth($row['total'], $row['base']),
                ...array_map(fn (int $month) => (float) ($row['months'][$month] ?? 0), range(1, 12)),
            ];
        }

        $categories = [];
        $charterMonths = [];
        $opexMonths = [];

        foreach ($output['categories'] as $row) {
            foreach ($row['months'] as $month => $cell) {
                $line = [
                    $row['key'],
                    self::MONTHS[$month] ?? $month,
                    $row['unit'] === PlanLine::SERVICE ? 'servicii' : 'pasageri',
                    (float) $cell['volume'],
                    (float) $cell['unit_net'],
                    (float) $cell['unit_margin'],
                    (float) $cell['amount'],
                    (float) $cell['margin'],
                    ($cell['manual'] ?? false) ? 'da' : '',
                    (string) ($cell['note'] ?? ''),
                ];

                if ($row['kind'] === PlanLine::CHARTER) {
                    $charterMonths[] = [
                        $row['key'],
                        self::MONTHS[$month] ?? $month,
                        (float) $cell['capacity'],
                        (float) $cell['load'],
                        (float) $cell['volume'],
                        (float) $cell['unit_net'],
                        (float) $cell['unit_cost'],
                        $cell['break_even'],
                        (float) $cell['amount'],
                        (float) $cell['margin'],
                        (float) $cell['empty_seats'],
                        (float) $cell['empty_cost'],
                        ($cell['manual'] ?? false) ? 'da' : '',
                    ];

                    continue;
                }

                $categories[] = $line;
            }
        }

        foreach ([...$output['opex'], ...$output['below']] as $row) {
            $opexMonths[] = [
                PnlReportService::BELOW_LABELS[$row['key']] ?? ($row['label'] ?: $row['key']),
                ...array_map(fn (int $month) => (float) ($row['months'][$month]['amount'] ?? 0), range(1, 12)),
                (float) $row['amount'],
                $row['manual'] ? 'da' : '',
            ];
        }

        $assumptions = array_map(fn (string $line) => [$line], $deck['assumptions']);

        return [
            [
                'name' => 'P&L plan',
                'headers' => ['Linie', $plan->base_year.' (lei)', 'Plan '.$plan->year.' (lei)', 'Δ (%)', ...array_map(fn (string $month) => $month.' (lei)', array_values(self::MONTHS))],
                'widths' => [46, 15, 15, 9, ...array_fill(0, 12, 13)],
                'rows' => $statement,
            ],
            [
                'name' => 'Pe luni',
                'headers' => ['Luna', 'Venit net (lei)', 'Marjă brută (lei)', 'Cheltuieli (lei)', 'EBITDA (lei)', 'Profit net (lei)', 'Pasageri (nr.)', 'Servicii (nr.)', 'Locuri (nr.)'],
                'widths' => [10, 16, 16, 16, 16, 16, 12, 12, 12],
                'rows' => array_map(fn (int $month) => [
                    self::MONTHS[$month],
                    (float) $output['months'][$month]['net'],
                    (float) $output['months'][$month]['margin'],
                    (float) $output['months'][$month]['opex'],
                    (float) $output['months'][$month]['ebitda'],
                    (float) $output['months'][$month]['net_profit'],
                    (float) $output['months'][$month]['pax'],
                    (float) $output['months'][$month]['services'],
                    (float) $output['months'][$month]['seats'],
                ], range(1, 12)),
                'chart' => [
                    'title' => 'Venit planificat pe luni',
                    'kind' => 'col', 'categories' => 0, 'values' => 1, 'limit' => 12,
                    'anchor' => ['col' => 10, 'row' => 1, 'cols' => 11, 'rows' => 20],
                ],
            ],
            [
                'name' => 'Pe categorii',
                'headers' => ['Categorie de produs', 'Pax / servicii (nr.)', 'Venit '.$plan->base_year.' (lei)', 'Venit '.$plan->year.' (lei)', 'Δ (%)', 'Marjă (lei)', 'Marjă (% din venit)', 'Profit net (lei)'],
                'widths' => [32, 14, 16, 16, 10, 16, 10, 16],
                'rows' => array_map(fn (array $row) => $row['cells'], $deck['sections'][1]['rows']),
                'chart' => [
                    'title' => 'Venit planificat pe categorii',
                    'kind' => 'bar', 'categories' => 0, 'values' => 3, 'limit' => 20,
                    'anchor' => ['col' => 9, 'row' => 1, 'cols' => 10, 'rows' => 24],
                ],
            ],
            [
                'name' => 'Pe canale',
                'headers' => ['Canal de vânzare', 'Pax / servicii (nr.)', 'Venit '.$plan->base_year.' (lei)', 'Venit '.$plan->year.' (lei)', 'Δ (%)', 'Marjă (lei)', 'Marjă (% din venit)', 'Profit net (lei)'],
                'widths' => [32, 14, 16, 16, 10, 16, 10, 16],
                'rows' => array_map(fn (array $row) => $row['cells'], $deck['sections'][2]['rows']),
                'chart' => [
                    'title' => 'Venit planificat pe canale',
                    'kind' => 'bar', 'categories' => 0, 'values' => 3, 'limit' => 12,
                    'anchor' => ['col' => 9, 'row' => 1, 'cols' => 10, 'rows' => 18],
                ],
            ],
            [
                'name' => 'Charter pe an',
                'headers' => ['Cursă', 'Locuri (nr.)', 'Ocupare (%)', 'Pasageri (nr.)', 'Venit/pax (lei)', 'Cost/loc (lei)', 'Prag (%)', 'Venit (lei)', 'Marjă (lei)', 'Locuri goale (nr.)', 'Cost locuri goale (lei)'],
                'widths' => [20, 12, 10, 12, 12, 12, 10, 16, 14, 12, 15],
                'rows' => array_map(fn (array $row) => $row['cells'], $deck['sections'][3]['rows']),
                'chart' => [
                    'title' => 'Venit planificat pe curse',
                    'kind' => 'bar', 'categories' => 0, 'values' => 7, 'limit' => 25,
                    'anchor' => ['col' => 12, 'row' => 1, 'cols' => 10, 'rows' => 28],
                ],
            ],
            [
                'name' => 'Charter pe luni',
                'headers' => ['Cursă', 'Luna', 'Locuri (nr.)', 'Grad de ocupare (0–1)', 'Pasageri (nr.)', 'Venit/pax (lei)', 'Cost/loc (lei)', 'Prag (0–1)', 'Venit (lei)', 'Marjă (lei)', 'Locuri goale (nr.)', 'Cost locuri goale (lei)', 'Scris de mână'],
                'widths' => [20, 8, 10, 14, 11, 11, 11, 9, 15, 14, 12, 15, 12],
                'rows' => $charterMonths,
            ],
            [
                'name' => 'Categorii pe luni',
                'headers' => ['Categorie', 'Luna', 'Se numără în', 'Volum (nr.)', 'Venit/unitate (lei)', 'Marjă/unitate (lei)', 'Venit (lei)', 'Marjă (lei)', 'Scris de mână', 'De unde vine cifra'],
                'widths' => [30, 8, 13, 12, 13, 13, 15, 15, 12, 40],
                'rows' => $categories,
            ],
            [
                'name' => 'Cheltuieli pe luni',
                'headers' => ['Linie', ...array_map(fn (string $month) => $month.' (lei)', array_values(self::MONTHS)), 'Total (lei)', 'Scris de mână'],
                'widths' => [44, ...array_fill(0, 12, 12), 15, 12],
                'rows' => $opexMonths,
            ],
            [
                'name' => 'Ipoteze',
                'headers' => [$deck['subtitle']],
                'widths' => [140],
                'rows' => $assumptions,
            ],
        ];
    }

    /**
     * Cifrele mari, de pus în capul hârtiei și al prezentării.
     *
     * @param  array<string, mixed>  $output
     * @return list<array{label: string, value: string, hint: string}>
     */
    public function summary(Plan $plan, array $output): array
    {
        $money = fn (float $value) => number_format($value, 0, ',', '.').' lei';

        $out = [];

        foreach ([
            ['Venit net', 'net', 'net'],
            ['Marjă brută', 'margin', 'margin'],
            ['EBITDA', 'ebitda', 'ebitda'],
            ['Profit net', 'net_profit', 'net_profit'],
        ] as [$label, $key, $baseKey]) {
            $was = $output['base'][$baseKey] ?? null;

            $out[] = [
                'label' => $label,
                'value' => $money((float) $output['totals'][$key]),
                'hint' => $was === null
                    ? 'plan '.$plan->year
                    : sprintf('%s față de %d (%s)', $this->growth((float) $output['totals'][$key], (float) $was) ?? '—', $plan->base_year, $money((float) $was)),
            ];
        }

        $out[] = [
            'label' => 'Pasageri · servicii · locuri',
            'value' => sprintf(
                '%s · %s · %s',
                number_format((float) $output['totals']['pax'], 0, ',', '.'),
                number_format((float) $output['totals']['services'], 0, ',', '.'),
                number_format((float) $output['totals']['seats'], 0, ',', '.'),
            ),
            'hint' => 'volumul din care ies banii',
        ];

        return $out;
    }

    /**
     * Planul ca prezentare: o filă de titlu, câte o filă pentru fiecare
     * bucată de tabel și, la urmă, ipotezele.
     *
     * @param  array<string, mixed>  $output
     * @return list<array<string, mixed>>
     */
    public function slides(Plan $plan, array $output, ?PlanVersion $version = null): array
    {
        $deck = $this->deck($plan, $output, $version);

        $slides = [[
            'title' => $deck['title'],
            'subtitle' => $deck['subtitle'],
            'table' => [
                'columns' => [
                    ['label' => 'Cifra', 'width' => 26],
                    ['label' => 'Plan '.$plan->year, 'width' => 22],
                    ['label' => 'Față de '.$plan->base_year, 'width' => 32],
                ],
                'rows' => array_map(fn (array $item) => [
                    'cells' => [$item['label'], $item['value'], $item['hint']],
                    'style' => 'normal',
                ], $this->summary($plan, $output)),
            ],
        ]];

        foreach ($deck['sections'] as $section) {
            $chunks = array_chunk($section['rows'], PptxWriter::ROWS_PER_SLIDE);

            foreach ($chunks as $index => $rows) {
                $slides[] = [
                    'title' => $section['title'].(count($chunks) > 1 ? sprintf(' (%d/%d)', $index + 1, count($chunks)) : ''),
                    'subtitle' => $index === 0 ? (string) ($section['note'] ?? '') : '',
                    'table' => ['columns' => $section['columns'], 'rows' => $rows],
                ];
            }
        }

        foreach (array_chunk($deck['assumptions'], 6) as $index => $bullets) {
            $slides[] = [
                'title' => $index === 0 ? 'Pe ce se sprijină cifrele' : 'Pe ce se sprijină cifrele (continuare)',
                'subtitle' => $index === 0 ? $deck['subtitle'] : '',
                'bullets' => $bullets,
            ];
        }

        return $slides;
    }

    private function growth(?float $now, ?float $before): ?string
    {
        if ($now === null || $before === null || abs($before) < 0.005) {
            return null;
        }

        $change = ($now - $before) / abs($before) * 100;

        return ($change >= 0 ? '+' : '−').number_format(abs($change), 1, ',', '.').'%';
    }
}
