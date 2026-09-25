<?php

namespace App\Services\Exports;

use App\Models\CashFlowSnapshot;
use Carbon\CarbonImmutable;

/**
 * Raportul WCFR 52 Weeks, pregătit pentru Excel și PDF: aceleași linii și
 * aceleași săptămâni ca pe ecran, luate din snapshotul deja construit. Nimic
 * nu se recalculează aici — un export care dă alte cifre decât pagina e mai
 * rău decât niciun export.
 */
class CashFlowExport
{
    /** Liniile care despart sau adună: se scot în evidență, ca pe ecran. */
    private const STRONG_KINDS = ['balance', 'total', 'subtotal', 'threshold'];

    public function document(CashFlowSnapshot $snapshot): ReportDocument
    {
        $payload = $snapshot->payload ?? [];
        $weeks = array_map('strval', $payload['weeks'] ?? []);
        $currency = (string) ($payload['currency'] ?? 'RON');

        $columns = [['label' => 'Linie', 'width' => 52]];

        foreach ($weeks as $index => $week) {
            $columns[] = [
                'label' => 'S+'.($index + 1)."\n".CarbonImmutable::parse($week)->format('d.m'),
                'width' => 13,
                'align' => 'right',
            ];
        }

        $rows = [];

        foreach ($payload['lines'] ?? [] as $line) {
            $kind = (string) ($line['kind'] ?? 'value');
            $code = trim((string) ($line['code'] ?? ''));
            $label = trim((string) ($line['label'] ?? ''));

            $cells = [ReportDocument::text(trim($code.' '.$label))];

            foreach ($weeks as $index => $week) {
                $value = $line['values'][$index] ?? null;

                // Liniile de text („comentariu”, praguri scrise în cuvinte) nu
                // au cifre: se scriu ca atare, altfel ajung 0 în Excel.
                $cells[] = $kind === 'text' || ! is_numeric($value)
                    ? ReportDocument::text((string) ($value ?? ''))
                    : ReportDocument::number((float) $value);
            }

            $rows[] = [
                'cells' => $cells,
                'style' => match (true) {
                    in_array($kind, self::STRONG_KINDS, true) => ReportDocument::STYLE_TOTAL,
                    $kind === 'reference' => ReportDocument::STYLE_GROUP,
                    default => ReportDocument::STYLE_NORMAL,
                },
            ];
        }

        $builtAt = $snapshot->built_at?->format('d.m.Y H:i') ?? '—';
        $opening = $payload['opening'] ?? [];

        return new ReportDocument(
            title: 'WCFR 52 Weeks — prognoză de trezorerie',
            subtitle: sprintf(
                'Christian Tour · %d săptămâni de la %s · valori în %s',
                count($weeks),
                $weeks === [] ? '—' : CarbonImmutable::parse($weeks[0])->format('d.m.Y'),
                $currency,
            ),
            columns: $columns,
            rows: $rows,
            notes: array_values(array_filter([
                'Raport construit la '.$builtAt.($snapshot->built_by !== null ? ' de '.$snapshot->built_by : '').'.',
                isset($opening['as_of'])
                    ? 'Poziția de trezorerie de pornire: '.CarbonImmutable::parse((string) $opening['as_of'])->format('d.m.Y').'.'
                    : null,
                'Cifrele sunt cele din snapshotul afișat în aplicație, inclusiv corecturile aplicate pe el.',
            ])),
            sheet: 'WCFR 52 Weeks',
            filename: 'wcfr-52-weeks-'.($weeks[0] ?? now()->toDateString()),
        );
    }
}
