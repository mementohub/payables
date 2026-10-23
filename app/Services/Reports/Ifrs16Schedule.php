<?php

namespace App\Services\Reports;

use App\Models\Lease;
use Illuminate\Support\Carbon;

/**
 * Calculul din standard pentru un contract de închiriere.
 *
 * La început, datoria e valoarea actuală a chiriilor rămase, actualizate cu
 * rata noastră de împrumut; dreptul de utilizare pornește de la aceeași
 * sumă. Pe urmă, lună de lună: dobânda crește datoria, plata o scade, iar
 * dreptul de utilizare se amortizează liniar pe toată durata.
 *
 * Nu e o estimare: din contract se iau chiria, durata și rata, iar restul e
 * aritmetică. Ce nu știm din contract — dacă se prelungește, cât se
 * indexează — se scrie în registru, nu se ghicește aici.
 */
class Ifrs16Schedule
{
    /**
     * Graficul lunar al contractului, de la începutul lui până la capăt.
     *
     * @return array<string, array{payment: float, interest: float, depreciation: float, liability: float, rou: float}>
     *                                                                                                                  cheia e „an-lună”, ca 2026-09
     */
    public function build(Lease $lease): array
    {
        $months = $lease->months();
        $rate = max(0.0, (float) $lease->discount_rate) / 12;
        $payment = (float) $lease->monthly_payment;
        $advance = (bool) $lease->in_advance;

        $liability = $this->presentValue($payment, $months, $rate, $advance);
        $rou = $liability;
        $depreciation = $months > 0 ? $rou / $months : 0.0;

        $schedule = [];
        $cursor = Carbon::parse($lease->starts_on)->startOfMonth();

        for ($i = 0; $i < $months; $i++) {
            // Chiria plătită înainte nu poartă dobândă în luna ei: scade
            // datoria chiar la început, iar dobânda curge din ce rămâne.
            $base = $advance ? $liability - $payment : $liability;
            $interest = max(0.0, $base) * $rate;
            $closing = $base + $interest - ($advance ? 0.0 : $payment);

            $schedule[$cursor->format('Y-m')] = [
                'payment' => round($payment, 2),
                'interest' => round($interest, 2),
                'depreciation' => round($depreciation, 2),
                'liability' => round(max(0.0, $closing), 2),
                'rou' => round(max(0.0, $rou - $depreciation * ($i + 1)), 2),
            ];

            $liability = $closing;
            $cursor->addMonth();
        }

        return $schedule;
    }

    /**
     * Ce intră în raport dintr-un an: amortizarea, dobânda și chiria pe care
     * o înlocuiesc, lună de lună.
     *
     * @param  iterable<int, Lease>  $leases
     * @param  callable(string, string): float|null  $rateFor  cursul lunii pentru moneda contractului
     * @return array{months: array<int, array{payment: float, interest: float, depreciation: float}>, totals: array{payment: float, interest: float, depreciation: float, liability: float}, leases: int}
     */
    public function forYear(iterable $leases, int $year, ?callable $rateFor = null): array
    {
        $months = [];
        $totals = ['payment' => 0.0, 'interest' => 0.0, 'depreciation' => 0.0, 'liability' => 0.0];
        $counted = 0;

        foreach ($leases as $lease) {
            $schedule = $this->build($lease);
            $counted++;

            // Dreptul de utilizare e un activ, nu o datorie: rămâne la cursul
            // de la început. Dobânda și plata se citesc la cursul lunii lor.
            $start = Carbon::parse($lease->starts_on)->format('Y-m');
            $startRate = $rateFor !== null ? $rateFor((string) $lease->currency, $start) : 1.0;

            foreach ($schedule as $key => $row) {
                [$scheduleYear, $month] = array_map('intval', explode('-', $key));

                if ($scheduleYear === $year + 1 || ($scheduleYear === $year && $month === 12)) {
                    // Datoria rămasă la sfârșitul anului, pentru rezumat.
                    if ($scheduleYear === $year && $month === 12) {
                        $totals['liability'] += $row['liability'] * ($rateFor !== null ? $rateFor((string) $lease->currency, $key) : 1.0);
                    }
                }

                if ($scheduleYear !== $year) {
                    continue;
                }

                $rate = $rateFor !== null ? $rateFor((string) $lease->currency, $key) : 1.0;

                $cell = $months[$month] ?? ['payment' => 0.0, 'interest' => 0.0, 'depreciation' => 0.0];
                $cell['payment'] += $row['payment'] * $rate;
                $cell['interest'] += $row['interest'] * $rate;
                $cell['depreciation'] += $row['depreciation'] * $startRate;
                $months[$month] = $cell;

                $totals['payment'] += $row['payment'] * $rate;
                $totals['interest'] += $row['interest'] * $rate;
                $totals['depreciation'] += $row['depreciation'] * $startRate;
            }
        }

        ksort($months);

        return [
            'months' => array_map(fn (array $cell) => array_map(fn (float $value) => round($value, 2), $cell), $months),
            'totals' => array_map(fn (float $value) => round($value, 2), $totals),
            'leases' => $counted,
        ];
    }

    /**
     * Valoarea actuală a chiriilor, la rata lunară dată.
     */
    private function presentValue(float $payment, int $months, float $rate, bool $advance): float
    {
        if ($rate <= 0.0) {
            return $payment * $months;
        }

        $ordinary = $payment * (1 - (1 + $rate) ** -$months) / $rate;

        // Plătită la început de lună, fiecare chirie se actualizează cu o lună
        // mai puțin: valoarea actuală e mai mare.
        return $advance ? $ordinary * (1 + $rate) : $ordinary;
    }
}
