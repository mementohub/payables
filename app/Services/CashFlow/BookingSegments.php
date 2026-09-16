<?php

namespace App\Services\CashFlow;

/**
 * Which line of the report a booking's receivables belong to, from the
 * channel it was booked through, the products it holds and its destination.
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
     * @param  array{channel: ?int, root_types: list<int>, continent: ?string}  $booking
     */
    public static function of(array $booking): string
    {
        $types = array_map('intval', $booking['root_types'] ?? []);
        $group = fn (string $name) => array_map('intval', (array) config("cashflow.etrip.product_types.{$name}", []));

        if ($booking['channel'] !== null && in_array((int) $booking['channel'], array_map('intval', (array) config('cashflow.etrip.sphinx_channels', [])), true)) {
            return self::SPHINX;
        }

        if ($types !== [] && array_intersect($types, $group('tour')) !== []) {
            return self::TOURS;
        }

        if ($types !== [] && array_diff($types, $group('flight')) === []) {
            return self::FLIGHTS;
        }

        $holiday = array_intersect($types, [...$group('package'), ...$group('hotel'), ...$group('charter')]) !== [];

        if ($holiday && self::isExotic($booking['continent'] ?? null)) {
            return self::EXOTIC;
        }

        if ($types !== [] && array_diff($types, $group('hotel')) === []) {
            return self::HOTEL;
        }

        if ($holiday) {
            return self::PACKAGES;
        }

        return self::OTHER;
    }

    public static function isExotic(?string $continent): bool
    {
        if ($continent === null || trim($continent) === '') {
            return false;
        }

        $home = array_map('mb_strtolower', (array) config('cashflow.etrip.home_continents', ['Europa']));

        return ! in_array(mb_strtolower(trim($continent)), $home, true);
    }

    public static function channel(string $clientType): string
    {
        return in_array($clientType, ['trade', 'business'], true) ? 'B2B' : 'B2C';
    }
}
