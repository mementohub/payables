<?php

namespace App\Services\Forecast;

use App\Services\Reports\TinaPnlReader;

/**
 * Corporate-ul din Tina, pentru plan.
 *
 * Tina e celălalt ERP: bilete de avion, cazări și evenimente vândute
 * firmelor. Acolo nu se numără pasageri, ci servicii facturate — un bilet
 * de avion e un serviciu, o cazare e alt serviciu — așa că pârghia planului
 * e serviciul, cu venitul și marja lui.
 *
 * Cea mai mare parte e Corporate, dar nu toată: în 2026 au trecut pe acolo
 * și cazări, și ticketing, iar planul le ia așa cum sunt, pe categoriile
 * lor, nu îngrămădite într-una singură.
 */
class ForecastTinaReader
{
    public function __construct(private TinaPnlReader $tina) {}

    /**
     * Pe categorie și lună: servicii facturate, venit net și marjă.
     *
     * @return list<array{product: string, month: int, services: int, net: float, margin: float}>
     */
    public function byProduct(int $year): array
    {
        $out = [];

        foreach ($this->tina->byChannelAndProduct($year) as $row) {
            $key = $row['product'].'|'.$row['month'];
            $cell = $out[$key] ?? [
                'product' => (string) $row['product'],
                'month' => (int) $row['month'],
                'services' => 0,
                'net' => 0.0,
                'margin' => 0.0,
            ];

            $out[$key] = [
                ...$cell,
                'services' => $cell['services'] + (int) $row['bookings'],
                'net' => round($cell['net'] + (float) $row['net'], 2),
                'margin' => round($cell['margin'] + (float) $row['margin'], 2),
            ];
        }

        return array_values($out);
    }
}
