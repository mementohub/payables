<?php

namespace App\Services\CashFlow;

use Carbon\CarbonImmutable;

/**
 * Turns an eTrip booking into the dated amounts still to be collected: the
 * scheduled advances (bookings.due_dates) plus the balance on its due date,
 * with what the client already paid applied to the earliest dates first.
 */
final class ReceivablesScheduler
{
    /**
     * @param  array{id: int, currency: string, total_due: float, paid: float, balance_due_date: ?string, start_date: ?string, due_dates: list<array{date: string, amount: float}>}  $booking
     * @return list<array{booking: int, date: string, amount: float, currency: string, type: string}>
     */
    public function tranches(array $booking, int $fallbackDaysBefore = 7): array
    {
        $total = round((float) $booking['total_due'], 2);
        $paid = round((float) $booking['paid'], 2);

        if ($total - $paid <= 0.005) {
            return [];
        }

        $advances = collect($booking['due_dates'] ?? [])
            ->filter(fn (array $row) => ! empty($row['date']) && (float) $row['amount'] > 0)
            ->sortBy('date')
            ->values();

        $scheduled = 0.0;
        $tranches = [];

        foreach ($advances as $row) {
            $amount = min(round((float) $row['amount'], 2), max(0.0, $total - $scheduled));

            if ($amount <= 0.005) {
                continue;
            }

            $tranches[] = ['date' => CarbonImmutable::parse($row['date'])->toDateString(), 'amount' => $amount, 'type' => 'avans'];
            $scheduled += $amount;
        }

        if ($total - $scheduled > 0.005) {
            $balanceDate = $booking['balance_due_date']
                ?? ($booking['start_date'] !== null
                    ? CarbonImmutable::parse($booking['start_date'])->subDays($fallbackDaysBefore)->toDateString()
                    : null);

            $tranches[] = [
                'date' => $balanceDate !== null ? CarbonImmutable::parse($balanceDate)->toDateString() : CarbonImmutable::today()->toDateString(),
                'amount' => round($total - $scheduled, 2),
                'type' => 'sold',
            ];
        }

        usort($tranches, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        $remainingPaid = $paid;
        $result = [];

        foreach ($tranches as $tranche) {
            $applied = min($remainingPaid, $tranche['amount']);
            $remainingPaid -= $applied;
            $open = round($tranche['amount'] - $applied, 2);

            if ($open > 0.005) {
                $result[] = [
                    'booking' => (int) $booking['id'],
                    'date' => $tranche['date'],
                    'amount' => $open,
                    'currency' => (string) $booking['currency'],
                    'type' => $tranche['type'],
                ];
            }
        }

        return $result;
    }
}
