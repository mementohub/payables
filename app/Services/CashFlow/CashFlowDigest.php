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
    /**
     * Ce intră la „cine ne plătește” și la „cui plătim”.
     *
     * Numai bucățile care au un nume în spate: un dosar, o factură, o plată.
     * Totalurile pe scenariu („curba anului trecut”) și resturile neexplicate
     * n-au contraparte, deci n-au ce căuta într-un top de nume.
     */
    private const IN_KINDS = ['tranche', 'overdue', 'tina', 'omc_receipt', 'tina_receipts'];

    private const OUT_KINDS = ['invoice', 'omc_payment', 'services', 'tickets', 'new_costs'];

    /** Etichete care nu sunt un partener, ci lipsa lui. */
    private const NO_PARTNER = ['Fără partener', 'Fara partener', 'neclasificat', '—', '-'];

    /** Câte documente se scriu sub fiecare nume. */
    private const DOCUMENTS = 2;

    /**
     * Cele mai mari sume ale săptămânii, strânse pe contraparte.
     *
     * Un partener are de obicei mai multe hârtii într-o săptămână; omul vrea
     * să știe întâi de cine atârnă suma, apoi care e documentul cel mai greu.
     *
     * @param  list<string>  $kinds
     * @param  list<string>  $prefixes  literele liniilor (B pentru încasări, C și D pentru plăți)
     * @return list<array<string, mixed>>
     */
    private function movers(CashFlowSnapshot $snapshot, string $week, array $kinds, array $prefixes, int $limit = 3): array
    {
        // Numai bucățile de prognoză: coloana săptămânii din tabel e tot
        // prognoză, iar ce s-a întâmplat deja e scris separat, pe alte rânduri.
        // Amestecate, topul ar aduna de două ori aceiași bani și ar ieși mai
        // mare decât totalul săptămânii.
        $base = fn () => CashFlowDetail::query()
            ->where('cash_flow_snapshot_id', $snapshot->id)
            ->whereDate('week', $week)
            ->where('actual', false)
            ->whereIn('kind', $kinds)
            ->whereNotNull('label')
            ->where('label', '!=', '')
            ->whereNotIn('label', self::NO_PARTNER)
            ->where(fn (Builder $query) => collect($prefixes)->each(fn (string $prefix) => $query->orWhere('line', 'like', $prefix.'%')));

        $groups = $base()
            ->select('label', DB::raw('sum(lei) as lei'), DB::raw('count(*) as pieces'))
            ->groupBy('label')
            ->orderByDesc(DB::raw('abs(sum(lei))'))
            ->limit($limit)
            ->get();

        $houses = $this->houseNames();

        return $groups->map(function ($group) use ($base, $houses) {
            // Cele mai grele hârtii ale partenerului: ele spun despre ce e vorba.
            $documents = $base()
                ->where('label', $group->label)
                ->orderByDesc(DB::raw('abs(lei)'))
                ->limit(self::DOCUMENTS)
                ->get();

            $label = (string) $group->label;

            return [
                'label' => $this->name($label, $houses),
                'lei' => round((float) $group->lei, 2),
                'pieces' => (int) $group->pieces,
                'documents' => $documents->map(fn (CashFlowDetail $piece) => [
                    'reference' => $piece->reference !== null && $piece->reference !== '' ? (string) $piece->reference : null,
                    'group' => $piece->group,
                    'date' => $piece->date?->format('d.m.Y'),
                    'currency' => $piece->currency,
                    'amount' => $piece->amount !== null ? round((float) $piece->amount, 2) : null,
                    'lei' => round((float) $piece->lei, 2),
                    'bank' => in_array($piece->kind, ['omc_payment', 'omc_receipt'], true),
                ])->all(),
                'rest' => max(0, (int) $group->pieces - $documents->count()),
            ];
        })->values()->all();
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
            'top_in' => $current !== null ? $this->movers($snapshot, $current, self::IN_KINDS, ['B']) : [],
            'top_out' => $current !== null ? $this->movers($snapshot, $current, self::OUT_KINDS, ['C', 'D']) : [],
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
