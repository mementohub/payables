<?php

namespace App\Services\CashFlow;

/**
 * Which line of the report a booking's receivables belong to: the product
 * type of its most expensive root item decides the segment, the channel it
 * was booked through decides B2B / B2C (config/cashflow.php).
 */
final class BookingSegments
{
    public const PACKAGES = 'pachete';

    public const TOURS = 'circuite';

    public const EXOTIC = 'exotic';

    public const SPHINX = 'sphinx';

    public const HOTEL = 'cazare';

    public const FLIGHTS = 'bilete';

    public const OTHER = 'altele';

    public const LABELS = [
        self::PACKAGES => 'Pachete charter/sejur',
        self::TOURS => 'Circuite',
        self::EXOTIC => 'Exotic',
        self::SPHINX => 'Sphinx',
        self::HOTEL => 'Cazare individuală',
        self::FLIGHTS => 'Bilete avion',
        self::OTHER => 'Altele',
    ];

    /**
     * @param  array{segment_type?: ?int}  $booking
     */
    public static function of(array $booking): string
    {
        $type = isset($booking['segment_type']) ? (int) $booking['segment_type'] : null;

        if ($type === null) {
            return self::OTHER;
        }

        foreach ((array) config('cashflow.etrip.segments', []) as $segment => $types) {
            if (array_key_exists($segment, self::LABELS) && in_array($type, array_map('intval', (array) $types), true)) {
                return $segment;
            }
        }

        return self::OTHER;
    }

    /**
     * @param  array{channel?: ?int}  $booking
     */
    public static function channel(array $booking): string
    {
        $channel = isset($booking['channel']) ? (int) $booking['channel'] : null;

        return $channel !== null && in_array($channel, array_map('intval', (array) config('cashflow.etrip.b2b_channels', [])), true) ? 'B2B' : 'B2C';
    }
}
