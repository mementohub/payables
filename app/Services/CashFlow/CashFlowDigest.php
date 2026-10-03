<?php

namespace App\Services\CashFlow;

use App\Models\CashFlowDetail;
use App\Models\CashFlowSnapshot;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Raportul de trezorerie, strâns cât încape într-un mail.
 *
 * Nu recalculează nimic: ia snapshotul construit noaptea și scoate din el
 * exact ce se citește dimineața pe telefon — câți bani sunt acum, ce intră
 * și ce iese în săptămânile apropiate și unde ajunge soldul. Restul rămâne
 * în fișierul atașat.
 */
class CashFlowDigest
{
    /** Etichete care nu sunt un partener, ci lipsa lui. */
    private const NO_PARTNER = ['Fără partener', 'Fara partener', 'neclasificat', '—', '-'];

    /** Câte bucăți se scriu sub fiecare linie. */
    private const DOCUMENTS = 2;

    /** Rândurile care adună alte rânduri: ele n-au ce căuta într-un top. */
    private const SUMS = ['total', 'balance', 'threshold', 'text', 'reference'];

    /**
     * Cele mai mari linii ale săptămânii în curs, cu ce stă sub ele.
     *
     * Topul se face pe liniile raportului, nu pe nume de parteneri: așa se
     * citește cu raportul alături, iar sumele sunt exact cele din coloana
     * săptămânii. Sub fiecare linie stau bucățile ei cele mai grele, cu
     * contrapartea și documentul lor.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $prefixes  literele liniilor (B pentru încasări, C și D pentru plăți)
     * @return list<array<string, mixed>>
     */
    private function topLines(CashFlowSnapshot $snapshot, array $payload, string $week, array $prefixes, int $limit = 3): array
    {
        $houses = $this->houseNames();

        $lines = collect($payload['lines'] ?? [])
            ->filter(function (array $line) use ($prefixes) {
                $code = (string) ($line['code'] ?? '');

                // Subtotalul și copiii lui spun aceiași bani de două ori; se
                // ține rândul de sus, ca în raport.
                return $code !== ''
                    && ! str_contains($code, '.')
                    && ! in_array((string) ($line['kind'] ?? 'value'), self::SUMS, true)
                    && collect($prefixes)->contains(fn (string $prefix) => str_starts_with($code, $prefix));
            })
            ->map(fn (array $line) => [
                'code' => (string) $line['code'],
                'label' => trim((string) ($line['label'] ?? '')),
                'lei' => round((float) ($line['values'][0] ?? 0), 2),
            ])
            ->filter(fn (array $line) => abs($line['lei']) >= 1)
            ->sortByDesc(fn (array $line) => abs($line['lei']))
            ->take($limit)
            ->values();

        return $lines->map(function (array $line) use ($snapshot, $week, $houses) {
            // Numai bucățile de prognoză: coloana săptămânii e tot prognoză,
            // iar ce s-a întâmplat deja e scris pe alte rânduri.
            $pieces = CashFlowDetail::query()
                ->where('cash_flow_snapshot_id', $snapshot->id)
                ->whereDate('week', $week)
                ->where('actual', false)
                ->where(fn (Builder $query) => $query
                    ->where('line', $line['code'])
                    ->orWhere('line', 'like', $line['code'].'.%'));

            $count = (clone $pieces)->count();

            $documents = (clone $pieces)
                ->orderByDesc(DB::raw('abs(lei)'))
                ->limit(self::DOCUMENTS)
                ->get();

            return [
                ...$line,
                'pieces' => $count,
                'documents' => $documents->map(fn (CashFlowDetail $piece) => [
                    'partner' => $this->name((string) $piece->label, $houses),
                    'reference' => $piece->reference !== null && $piece->reference !== '' ? (string) $piece->reference : null,
                    'group' => $piece->group,
                    'date' => $piece->date?->format('d.m.Y'),
                    'currency' => $piece->currency,
                    'amount' => $piece->amount !== null ? round((float) $piece->amount, 2) : null,
                    'lei' => round((float) $piece->lei, 2),
                    'bank' => in_array($piece->kind, ['omc_payment', 'omc_receipt'], true),
                ])->all(),
                'rest' => max(0, $count - $documents->count()),
            ];
        })->all();
    }

    /**
     * Numele sub care intră în mail contrapartea.
     *
     * Pe vânzările proprii, „clientul” dosarului e chiar agenția noastră, iar
     * un om care citește că are de încasat de la el însuși pierde timp până
     * înțelege; așa că se numește cum e. Firmele din grup rămân cu numele lor,
     * dar scriu pe ele că sunt din grup — banii sunt adevărați, numai că nu
     * vin de la o piață din afară.
     *
     * @param  list<string>  $houses
     */
    private function name(string $label, array $houses): string
    {
        $needle = mb_strtolower(trim($label));

        if ($label === '' || in_array(trim($label), self::NO_PARTNER, true)) {
            return 'fără partener';
        }

        if (in_array($needle, $houses, true)) {
            return 'Clienți direcți (retail)';
        }

        foreach ((array) config('notifications.group_partners', []) as $group) {
            if ($group !== '' && str_contains($needle, mb_strtolower((string) $group))) {
                return $label.' (intragrup)';
            }
        }

        return $label;
    }

    /**
     * Numele companiilor noastre, cu litere mici, pentru recunoaștere.
     *
     * @return list<string>
     */
    private function houseNames(): array
    {
        return Company::query()
            ->pluck('name')
            ->map(fn (string $name) => mb_strtolower(trim($name)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function for(CashFlowSnapshot $snapshot, int $weeks = 8): array
    {
        $payload = $snapshot->payload ?? [];
        $opening = $payload['opening'] ?? [];
        $lines = collect($payload['lines'] ?? [])->keyBy(fn (array $line) => (string) ($line['code'] ?? ''));
        $allWeeks = array_map('strval', $payload['weeks'] ?? []);
        $horizon = array_slice($allWeeks, 0, max(1, $weeks), true);

        $value = fn (string $code, int $index) => (float) ($lines[$code]['values'][$index] ?? 0.0);

        $rows = [];

        foreach ($horizon as $index => $week) {
            $in = $value('B', $index);
            $out = $value('C', $index) + $value('D', $index);

            $rows[] = [
                'index' => $index + 1,
                'week' => CarbonImmutable::parse($week)->format('d.m.Y'),
                'in' => $in,
                'out' => $out,
                'net' => $value('E1', $index),
                'closing' => $value('E2', $index),
            ];
        }

        $current = $allWeeks[0] ?? null;
        $kpis = $payload['kpis'] ?? [];
        $minimum = $kpis['min_closing'] ?? null;

        return [
            'built_at' => $snapshot->built_at,
            'status' => $snapshot->status,
            'as_of' => $opening['as_of'] ?? null,
            'currency' => (string) ($payload['currency'] ?? 'RON'),
            // Poziția de azi, pe fiecare monedă în care chiar sunt bani.
            'position' => collect($opening['by_currency'] ?? [])
                ->filter(fn ($amount) => abs((float) $amount) >= 0.01)
                ->map(fn ($amount) => (float) $amount)
                ->all(),
            'position_total' => (float) ($opening['total'] ?? 0.0),
            'weeks' => $rows,
            // Săptămâna în curs, cu numele celor mai mari sume de o parte și
            // de alta: din ele se vede imediat de cine atârnă săptămâna.
            'current_week' => $current !== null ? [
                'from' => CarbonImmutable::parse($current)->format('d.m'),
                'to' => CarbonImmutable::parse($current)->addDays(6)->format('d.m.Y'),
            ] : null,
            'top_in' => $current !== null ? $this->topLines($snapshot, $payload, $current, ['B']) : [],
            'top_out' => $current !== null ? $this->topLines($snapshot, $payload, $current, ['C', 'D']) : [],
            'closing_13' => isset($kpis['closing_13']) ? (float) $kpis['closing_13'] : null,
            'in_13' => isset($kpis['in_13']) ? (float) $kpis['in_13'] : null,
            'out_13' => isset($kpis['out_13']) ? (float) $kpis['out_13'] : null,
            'min_closing' => $minimum !== null ? [
                'week' => CarbonImmutable::parse((string) $minimum['week'])->format('d.m.Y'),
                'value' => (float) $minimum['value'],
            ] : null,
            // Ce n-a răspuns la construire: cifrele de mai sus sunt întregi doar
            // dacă lista asta e goală.
            'problems' => collect($snapshot->sources ?? [])
                ->filter(fn ($source) => ! empty($source['error']) || ($source['status'] ?? 'ok') !== 'ok')
                ->map(fn ($source) => trim((string) ($source['label'] ?? $source['key'] ?? 'sursă')).': '
                    .trim((string) ($source['error'] ?? $source['message'] ?? 'nu a răspuns')))
                ->values()
                ->all(),
        ];
    }
}
