<?php

namespace App\Services\Exports;

use App\Models\Company;
use App\Services\Reports\PnlReportService;
use Illuminate\Support\Carbon;

/**
 * Contul de profit și pierdere, pregătit pentru Excel și PDF, din chiar
 * vederea pe care o desenează pagina: aceleași coloane, aceleași grupe în
 * aceeași ordine, aceleași totaluri.
 */
class PnlExport
{
    /** @var array<string, string> */
    private const PERIODS = [
        'year' => 'anul întreg',
        'q1' => 'trimestrul I cumulat',
        'q2' => 'semestrul I cumulat',
        'q3' => 'trimestrele I–III cumulate',
        'q4' => 'anul întreg',
    ];

    /** @var array<string, string> */
    private const MONTHS = [
        1 => 'ianuarie', 2 => 'februarie', 3 => 'martie', 4 => 'aprilie',
        5 => 'mai', 6 => 'iunie', 7 => 'iulie', 8 => 'august',
        9 => 'septembrie', 10 => 'octombrie', 11 => 'noiembrie', 12 => 'decembrie',
    ];

    /**
     * @param  array<string, mixed>  $view  Raportul tăiat pe perioadă, ca pe pagină.
     */
    public function document(Company $company, array $view, string $axis): ReportDocument
    {
        $keys = $axis === 'product' ? ($view['products'] ?? []) : ($view['channels'] ?? []);
        $channels = (array) config('pnl.channels', []);
        $label = fn (string $key) => $axis === 'product'
            ? ($key === '- -' ? 'Fără categorie' : $key)
            : ($channels[$key] ?? $key);

        $columns = [
            ['label' => 'Linie', 'width' => 52],
            ['label' => 'Total', 'width' => 16, 'align' => 'right'],
        ];

        foreach ($keys as $key) {
            $columns[] = ['label' => $label((string) $key), 'width' => 16, 'align' => 'right'];
        }

        $revenue = $view['revenue'] ?? [];
        $byKey = $axis === 'product' ? ($revenue['by_product'] ?? []) : ($revenue['by_channel'] ?? []);
        $costKey = $axis === 'product' ? 'by_product' : 'by_channel';

        $rows = [];

        $rows[] = $this->row('Venit net', (float) ($revenue['total']['net'] ?? 0), array_map(
            fn ($key) => (float) ($byKey[$key]['net'] ?? 0),
            $keys,
        ), ReportDocument::STYLE_SECTION);

        if (isset($revenue['cogs'])) {
            $rows[] = $this->row('Cost servicii vândute (628)', (float) $revenue['cogs'], array_map(
                fn ($key) => (float) (($byKey[$key]['net'] ?? 0) - ($byKey[$key]['margin'] ?? 0)),
                $keys,
            ));
        }

        if (isset($revenue['fx_commission']) && abs((float) $revenue['fx_commission']['total']) > 0.5) {
            $fx = $revenue['fx_commission'];
            $rows[] = $this->row((string) $fx['label'], (float) $fx['total'], array_map(
                fn ($key) => (float) ($fx[$costKey][$key] ?? 0),
                $keys,
            ));
        }

        $rows[] = $this->row('Marjă brută', (float) ($revenue['total']['margin'] ?? 0), array_map(
            fn ($key) => (float) ($byKey[$key]['margin'] ?? 0),
            $keys,
        ), ReportDocument::STYLE_SECTION);

        foreach ($this->groups($view) as $group => $lines) {
            $rows[] = $this->row($group, array_sum(array_column($lines, 'total')), array_map(
                fn ($key) => array_sum(array_map(fn (array $line) => (float) ($line[$costKey][$key] ?? 0), $lines)),
                $keys,
            ), ReportDocument::STYLE_GROUP);

            foreach ($lines as $line) {
                $rows[] = $this->row(
                    '    '.$line['saf'].' '.$line['label'],
                    (float) $line['total'],
                    array_map(fn ($key) => (float) ($line[$costKey][$key] ?? 0), $keys),
                );
            }
        }

        $totals = $view['totals'] ?? [];
        $costOf = fn (string $key) => (float) (($totals[$costKey][$key] ?? 0));

        $rows[] = $this->row('Cheltuieli totale operaționale', (float) ($totals['total'] ?? 0), array_map(
            fn ($key) => $costOf((string) $key),
            $keys,
        ), ReportDocument::STYLE_SECTION);

        $ebitda = (float) ($revenue['total']['margin'] ?? 0) - (float) ($totals['total'] ?? 0);
        $rows[] = $this->row('EBITDA', $ebitda, array_map(
            fn ($key) => (float) ($byKey[$key]['margin'] ?? 0) - $costOf((string) $key),
            $keys,
        ), ReportDocument::STYLE_SECTION);

        $below = $view['below'] ?? [];
        $belowOf = fn (string $bucket, ?string $key = null) => $key === null
            ? (float) ($below[$bucket]['total'] ?? 0)
            : (float) ($below[$bucket][$costKey][$key] ?? 0);

        foreach (['amortizare' => 'Amortizare', 'financiar' => 'Rezultat financiar (net)', 'impozit' => 'Impozit pe profit'] as $bucket => $title) {
            $rows[] = $this->row($title, $belowOf($bucket), array_map(
                fn ($key) => $belowOf($bucket, (string) $key),
                $keys,
            ));
        }

        $net = $ebitda - $belowOf('amortizare') - $belowOf('financiar') - $belowOf('impozit');
        $rows[] = $this->row('Profit net', $net, array_map(
            fn ($key) => (float) ($byKey[$key]['margin'] ?? 0) - $costOf((string) $key)
                - $belowOf('amortizare', (string) $key) - $belowOf('financiar', (string) $key) - $belowOf('impozit', (string) $key),
            $keys,
        ), ReportDocument::STYLE_SECTION);

        return new ReportDocument(
            title: 'Cont de profit și pierdere',
            subtitle: $this->subtitle($company, $view, $axis),
            columns: $columns,
            rows: $rows,
            notes: $this->notes($view),
            sheet: 'P&L '.($view['year'] ?? ''),
            filename: sprintf('pnl-%s-%s-%s', $view['year'] ?? 'an', $view['period'] ?? 'year', $axis),
        );
    }

    /**
     * Grupele de cheltuieli, în ordinea de pe ecran: cea mai scumpă prima.
     *
     * @param  array<string, mixed>  $view
     * @return array<string, list<array<string, mixed>>>
     */
    private function groups(array $view): array
    {
        $groups = [];

        foreach ($view['lines'] ?? [] as $line) {
            $groups[(string) $line['group']][] = $line;
        }

        uasort($groups, fn (array $a, array $b) => array_sum(array_column($b, 'total')) <=> array_sum(array_column($a, 'total')));

        return $groups;
    }

    /**
     * @param  list<float>  $values
     * @return array{cells: list<mixed>, style: string}
     */
    private function row(string $label, float $total, array $values, string $style = ReportDocument::STYLE_NORMAL): array
    {
        return [
            'cells' => [
                ReportDocument::text($label),
                ReportDocument::number(round($total, 2)),
                ...array_map(fn (float $value) => ReportDocument::number(round($value, 2)), $values),
            ],
            'style' => $style,
        ];
    }

    /**
     * @param  array<string, mixed>  $view
     */
    private function subtitle(Company $company, array $view, string $axis): string
    {
        $basis = ($view['basis'] ?? PnlReportService::BASIS_RAS) === PnlReportService::BASIS_IFRS16 ? 'IFRS 16' : 'statutar';
        $mode = ($view['mode'] ?? PnlReportService::MODE_OPERATIONAL) === PnlReportService::MODE_FINANCIAL
            ? 'venit și COGS din contabilitate'
            : 'venit și COGS din eTrip';

        return sprintf(
            '%s · %s · %s · pe %s · chirii %s · %s',
            $company->name,
            $view['year'] ?? '',
            $this->period((string) ($view['period'] ?? 'year')),
            $axis === 'product' ? 'categorie de produs' : 'canal de vânzare',
            $basis,
            $mode,
        );
    }

    private function period(string $period): string
    {
        if (preg_match('/^(mtd|ytd|m)(\d{1,2})$/', $period, $matches) === 1) {
            $month = self::MONTHS[(int) $matches[2]] ?? $period;

            return $matches[1] === 'mtd' ? 'luna '.$month : 'cumulat până în '.$month;
        }

        return self::PERIODS[$period] ?? $period;
    }

    /**
     * @param  array<string, mixed>  $view
     * @return list<string>
     */
    private function notes(array $view): array
    {
        $meta = $view['meta'] ?? [];

        return array_values(array_filter([
            isset($meta['generated_at'])
                ? 'Raport construit la '.Carbon::parse((string) $meta['generated_at'])->format('d.m.Y H:i').'.'
                : null,
            isset($meta['direct_lei'], $meta['allocated_lei'])
                ? sprintf(
                    'Din cheltuieli, %s lei sunt legate direct de un punct de lucru, iar %s lei sunt repartizate pe cheia de venit.',
                    number_format((float) $meta['direct_lei'], 0, ',', '.'),
                    number_format((float) $meta['allocated_lei'], 0, ',', '.'),
                )
                : null,
            isset($meta['excluded_accounts'])
                ? 'Conturi excluse din EBITDA: '.implode(', ', (array) $meta['excluded_accounts']).'.'
                : null,
            ($meta['reconciles'] ?? true) ? null : 'ATENȚIE: vederile pe canal și pe produs nu închid pe același total.',
        ]));
    }
}
