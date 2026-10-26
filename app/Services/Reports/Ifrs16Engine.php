<?php

namespace App\Services\Reports;

use App\Models\Lease;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Mișcarea anului pe IFRS 16: dreptul de utilizare și datoria de leasing.
 *
 * Un portofoliu de închirieri nu stă pe loc. În cursul anului se semnează
 * contracte noi, se prelungesc altele, se reevaluează durate, se închid
 * spații. Standardul cere ca fiecare din ele să se vadă separat:
 *
 *   - contract nou sau prelungire: se recunoaște valoarea actuală a
 *     chiriilor rămase, și ca activ, și ca datorie;
 *   - remăsurare (IFRS 16.39-46): linia veche se închide, iar diferența
 *     dintre activul și datoria ei trece pe linia nouă — fără efect în
 *     contul de profit și pierdere;
 *   - încetare: ies amândouă, iar ce rămâne între ele e câștig sau
 *     pierdere;
 *   - lună de lună: dobânda crește datoria, plata o scade, iar activul se
 *     amortizează liniar.
 *
 * Soldurile de la care pornește anul sunt cele auditate la 31 decembrie: nu
 * se reface istoria, se continuă din ea. Ce se întâmplă în an se calculează
 * aici, linie cu linie, în moneda contractului; trecerea în lei se face la
 * urmă, fiindcă activul e nemonetar (rămâne la cursul de la recunoaștere),
 * iar datoria e în valută și se reevaluează la cursul de închidere.
 */
class Ifrs16Engine
{
    /**
     * Mișcarea unei perioade, linie cu linie și pe total.
     *
     * @param  Collection<int, Lease>  $leases
     * @return array<string, mixed>
     */
    public function rollForward(Collection $leases, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfMonth();
        $to = $to->copy()->endOfMonth();

        // Ordinea contează: o linie nouă preia activul și datoria celei pe
        // care o înlocuiește, deci linia veche se socotește prima. În
        // calculul societății linia succesoare are mereu un număr mai mare.
        $lines = $leases->sortBy(fn (Lease $lease) => [(int) ($lease->line_no ?? 0), (string) $lease->starts_on])->values();
        $rows = [];

        foreach ($lines as $lease) {
            $rows[$this->key($lease)] = $this->line($lease, $lines, $rows, $from, $to);
        }

        return ['lines' => $rows, 'totals' => $this->totals($rows)];
    }

    /**
     * Cheia liniei: numărul din calculul societății, dacă există.
     */
    private function key(Lease $lease): string
    {
        return $lease->line_no !== null ? (string) (int) $lease->line_no : 'id:'.$lease->getKey();
    }

    /**
     * O linie, de la soldul de deschidere până la cel de la sfârșitul
     * perioadei, cu lunile dintre ele.
     *
     * @param  Collection<int, Lease>  $lines
     * @param  array<string, array<string, mixed>>  $done
     * @return array<string, mixed>
     */
    private function line(Lease $lease, Collection $lines, array $done, Carbon $from, Carbon $to): array
    {
        $start = Carbon::parse($lease->starts_on)->startOfMonth();
        $term = $lease->months();
        $payment = (float) $lease->monthly_payment;
        $rate = max(0.0, (float) $lease->discount_rate) / 12;
        $opening = $lease->opening_on !== null ? Carbon::parse($lease->opening_on)->endOfMonth() : null;
        $before = $opening !== null && $start->lte($opening);

        // Ultima lună a liniei: durata contractului, dar nu peste eveniment.
        $last = $start->copy()->addMonths(max(0, $term - 1))->endOfMonth();
        $event = $lease->event_on !== null ? Carbon::parse($lease->event_on)->endOfMonth() : null;
        $stop = $event !== null && $event->lt($last) ? $event : $last;

        // Dreptul de utilizare al grupului: valoarea actuală a liniilor lui
        // recunoscute acum, plus diferența preluată de la liniile pe care
        // le înlocuiește.
        $recognised = 0.0;
        $adjustment = 0.0;

        if ($lease->leads()) {
            foreach ($lines as $one) {
                if ($one->event === Lease::CANCELLED) {
                    continue;
                }

                $its = Carbon::parse($one->starts_on)->startOfMonth();
                $mine = (int) ($one->group_no ?? $one->line_no) === (int) ($lease->line_no ?? 0)
                    || ($lease->line_no === null && $one->is($lease));

                if ($mine && $its->lte($to) && ($opening === null || $its->gt($opening))) {
                    $recognised += $this->value($one);
                }
            }

            foreach ($done as $one) {
                if ($one['successor'] === (int) $lease->line_no) {
                    $adjustment += $one['rou_out'] - $one['liability_out'];
                }
            }
        }

        $base = ($before ? (float) ($lease->opening_rou ?? 0) : 0.0) + $recognised + $adjustment;

        // Amortizarea lunară: a liniei vechi e cea din calculul auditat, a
        // celei noi iese din valoarea recunoscută acum.
        $monthly = 0.0;

        if ($lease->leads() && $term > 0) {
            $monthly = $before
                ? (float) ($lease->monthly_depreciation ?? ($base / $term))
                : $base / $term;
        }

        // Datoria pornește din soldul auditat sau din valoarea actuală a
        // liniei, dacă începe acum.
        $liability = $before
            ? (float) ($lease->opening_liability ?? 0)
            : ($lease->event === Lease::CANCELLED ? 0.0 : $this->value($lease));

        $months = [];
        $rou = $base;
        $taken = 0.0;
        $interest = 0.0;
        $paid = 0.0;
        $depreciation = 0.0;

        $cursor = $before ? $opening->copy()->addDay()->startOfMonth() : $start->copy();

        // Mersul lunilor se oprește la sfârșitul perioadei cerute: soldurile
        // de la capătul contractului nu sunt soldurile de acum.
        $walk = $stop->lt($to) ? $stop : $to;

        while ($cursor->lte($walk)) {
            $inside = $cursor->gte($from) && $cursor->lte($to);
            $cell = ['depreciation' => 0.0, 'interest' => 0.0, 'payment' => 0.0];

            if ($monthly !== 0.0 && $taken + abs($monthly) <= abs($base) + 0.005) {
                $cell['depreciation'] = $monthly;
                $taken += abs($monthly);
                $rou -= $monthly;
            }

            if (abs($liability) > 0.005 || abs($payment) > 0.005) {
                // Chiria plătită la început de lună nu poartă dobândă în
                // luna ei: scade datoria chiar atunci, iar dobânda curge
                // din ce rămâne.
                $owed = $lease->in_advance ? $liability - $payment : $liability;
                $cell['interest'] = $owed * $rate;
                $cell['payment'] = $payment;
                $liability = $owed + $cell['interest'] + ($lease->in_advance ? 0.0 : -$payment);
            }

            if ($inside) {
                $months[$cursor->format('Y-m')] = $cell;
                $depreciation += $cell['depreciation'];
                $interest += $cell['interest'];
                $paid += $cell['payment'];
            }

            $cursor->addMonth();
        }

        // Ce se întâmplă la eveniment: linia iese din portofoliu.
        $out = $event !== null && $event->lte($to) && in_array($lease->event, [Lease::REMEASURE, Lease::DERECOGNISE], true);
        $rouOut = $out ? $rou : 0.0;
        $liabilityOut = $out ? $liability : 0.0;

        return [
            'line_no' => $lease->line_no === null ? null : (int) $lease->line_no,
            'label' => $lease->label(),
            'currency' => (string) $lease->currency,
            'start' => $start->toDateString(),
            'end' => $last->toDateString(),
            'months' => $months,
            'event' => $lease->event,
            'event_on' => $event?->toDateString(),
            'successor' => $lease->successor_no === null ? null : (int) $lease->successor_no,
            'category' => $lease->category,
            'base' => round($base, 6),
            'recognised' => round($recognised + $adjustment, 6),
            'opening_rou' => $before ? round((float) ($lease->opening_rou ?? 0), 6) : 0.0,
            'opening_liability' => $before ? round((float) ($lease->opening_liability ?? 0), 6) : 0.0,
            'depreciation' => round($depreciation, 6),
            'interest' => round($interest, 6),
            'payments' => round($paid, 6),
            'rou_out' => round($rouOut, 6),
            'liability_out' => round($liabilityOut, 6),
            'gain' => $lease->event === Lease::DERECOGNISE ? round($liabilityOut - $rouOut, 6) : 0.0,
            'rou_close' => $out ? 0.0 : round($rou, 6),
            'liability_close' => $out ? 0.0 : round($liability, 6),
            'maturity' => $out ? ['<1y' => 0.0, '1-5y' => 0.0, '>5y' => 0.0] : $this->maturity($payment, $stop, $to),
            'current' => $out ? 0.0 : round($this->current($liability, $payment, $rate, $stop, $to), 6),
        ];
    }

    /**
     * Valoarea actuală a chiriilor unei linii, la începerea ei.
     *
     * Dacă a fost calculată și auditată, se ia ca atare; altfel se
     * socotește aici, din chirie, durată și rată. Chiria plătită la început
     * de lună nu poartă dobândă în luna ei, deci valoarea ei actuală e cu o
     * lună mai mare.
     */
    private function value(Lease $lease): float
    {
        if ($lease->present_value !== null) {
            return (float) $lease->present_value;
        }

        $months = $lease->months();
        $rate = max(0.0, (float) $lease->discount_rate) / 12;
        $payment = (float) $lease->monthly_payment;

        if ($rate <= 0.0) {
            return $payment * $months;
        }

        $value = $payment * (1 - (1 + $rate) ** -$months) / $rate;

        return $lease->in_advance ? $value * (1 + $rate) : $value;
    }

    /**
     * Plățile rămase, neactualizate, pe scadențe — ca în nota la situații.
     *
     * @return array<string, float>
     */
    private function maturity(float $payment, Carbon $stop, Carbon $to): array
    {
        $out = ['<1y' => 0.0, '1-5y' => 0.0, '>5y' => 0.0];
        $cursor = $to->copy()->addMonth()->startOfMonth();
        $year = $to->copy()->addYear();
        $five = $to->copy()->addYears(5);

        while ($cursor->lte($stop)) {
            $where = $cursor->lte($year) ? '<1y' : ($cursor->lte($five) ? '1-5y' : '>5y');
            $out[$where] += $payment;
            $cursor->addMonth();
        }

        return array_map(fn (float $value) => round($value, 6), $out);
    }

    /**
     * Partea de principal scadentă în următoarele douăsprezece luni.
     */
    private function current(float $liability, float $payment, float $rate, Carbon $stop, Carbon $to): float
    {
        $cursor = $to->copy()->addMonth()->startOfMonth();
        $limit = $to->copy()->addYear();
        $balance = $liability;
        $principal = 0.0;

        while ($cursor->lte($stop) && $cursor->lte($limit)) {
            $interest = $balance * $rate;
            $principal += $payment - $interest;
            $balance = $balance + $interest - $payment;
            $cursor->addMonth();
        }

        return min(abs($liability), max(0.0, $principal)) * ($liability < 0 ? -1 : 1);
    }

    /**
     * Totalurile portofoliului, în moneda contractelor.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows): array
    {
        $sum = fn (string $key) => round(array_sum(array_map(fn (array $row) => (float) $row[$key], $rows)), 2);

        $months = [];

        foreach ($rows as $row) {
            foreach ($row['months'] as $month => $cell) {
                $before = $months[$month] ?? ['depreciation' => 0.0, 'interest' => 0.0, 'payment' => 0.0];

                $months[$month] = [
                    'depreciation' => $before['depreciation'] + $cell['depreciation'],
                    'interest' => $before['interest'] + $cell['interest'],
                    'payment' => $before['payment'] + $cell['payment'],
                ];
            }
        }

        ksort($months);

        $maturity = ['<1y' => 0.0, '1-5y' => 0.0, '>5y' => 0.0];

        foreach ($rows as $row) {
            foreach ($row['maturity'] as $key => $value) {
                $maturity[$key] += $value;
            }
        }

        $derecognised = array_filter($rows, fn (array $row) => $row['event'] === Lease::DERECOGNISE && $row['event_on'] !== null);
        $remeasured = array_filter($rows, fn (array $row) => $row['event'] === Lease::REMEASURE && $row['event_on'] !== null);

        return [
            'opening_rou' => $sum('opening_rou'),
            'opening_liability' => $sum('opening_liability'),
            'recognised' => $sum('recognised'),
            'depreciation' => $sum('depreciation'),
            'interest' => $sum('interest'),
            'payments' => $sum('payments'),
            'rou_derecognised' => round(array_sum(array_map(fn (array $row) => $row['rou_out'], $derecognised)), 2),
            'liability_derecognised' => round(array_sum(array_map(fn (array $row) => $row['liability_out'], $derecognised)), 2),
            'rou_transferred' => round(array_sum(array_map(fn (array $row) => $row['rou_out'], $remeasured)), 2),
            'liability_transferred' => round(array_sum(array_map(fn (array $row) => $row['liability_out'], $remeasured)), 2),
            'gain' => $sum('gain'),
            'rou_close' => $sum('rou_close'),
            'liability_close' => $sum('liability_close'),
            'current' => $sum('current'),
            'long_term' => round($sum('liability_close') - $sum('current'), 2),
            'maturity' => array_map(fn (float $value) => round($value, 2), $maturity),
            'months' => array_map(fn (array $cell) => array_map(fn (float $value) => round($value, 2), $cell), $months),
            'lines' => count($rows),
        ];
    }
}
