<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Venitul și marja din Tina, pe canal de vânzare și categorie de produs.
 *
 * Tina e un ERP separat, ca eTrip, pentru business-ul corporate: bilete de
 * avion, cazări și evenimente vândute firmelor. Departamentul care răspunde de
 * comandă spune canalul — „Corporate” e canal de vânzare, alături de B2B și
 * Retail, nu categorie de produs. Ce s-a vândut spune serviciul: un bilet
 * rămâne Ticketing, o cazare rămâne Cazare, oricine ar fi cumpărat.
 *
 * Banii se citesc din oferta serviciului, acolo unde Tina ține comerțul:
 * `offerTotal` e prețul de vânzare, `supplierValue` plus TVA-ul lui e costul
 * (COGS), iar diferența e marja. Valorile sunt în moneda ofertei, cu cursul
 * zilei pe ea.
 */
class TinaPnlReader
{
    /**
     * Venit și marjă pe (canal, produs, lună) pentru un an calendaristic, în
     * lei, după data facturii serviciului (sau data serviciului, dacă încă nu
     * e facturat).
     *
     * @return list<array{channel: string, branch: string, product: string, month: int, bookings: int, net: float, margin: float}>
     */
    public function byChannelAndProduct(int $year): array
    {
        $rows = DB::connection((string) config('pnl.tina.connection', 'tina'))->select(
            $this->sql(),
            [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)],
        );

        $map = (array) config('pnl.tina.departments', []);
        $services = (array) config('pnl.tina.service_products', []);
        $codes = (array) config('pnl.tina.service_codes', []);
        $fallbackChannel = (string) config('pnl.tina.default_channel', 'corporate');
        $fallbackProduct = (string) config('pnl.tina.default_product', 'Corporate');

        $out = [];

        foreach ($rows as $row) {
            [$channel, $product] = $this->place(
                (string) ($row->department ?? ''),
                (string) ($row->category ?? ''),
                $map,
                $services,
                $fallbackChannel,
                $fallbackProduct,
                (string) ($row->code ?? ''),
                $codes,
            );

            $key = $channel.'|'.$product.'|'.(int) $row->month;
            $cell = $out[$key] ?? ['channel' => $channel, 'branch' => 'Tina', 'product' => $product, 'month' => (int) $row->month, 'bookings' => 0, 'net' => 0.0, 'margin' => 0.0];

            $out[$key] = [
                ...$cell,
                'bookings' => $cell['bookings'] + (int) $row->services,
                'net' => round($cell['net'] + (float) $row->net, 2),
                'margin' => round($cell['margin'] + (float) $row->margin, 2),
            ];
        }

        return array_values($out);
    }

    /**
     * Unde cade o comandă: canalul și produsul.
     *
     * Departamentul comenzii dă canalul, serviciul dă produsul. Un departament
     * nu poate numi produsul: „Corporate” spune cine a cumpărat, nu ce.
     *
     * @param  array<string, array{product?: ?string, channel?: string}>  $map
     * @param  array<string, string>  $services
     * @return array{0: string, 1: string}
     */
    public function place(string $department, string $category, array $map, array $services, string $fallbackChannel, string $fallbackProduct, string $code = '', array $codes = []): array
    {
        $rules = $map[$department] ?? [];
        $channel = (string) ($rules['channel'] ?? $fallbackChannel);
        $product = $rules['product'] ?? null;

        if ($product === null || $product === '') {
            // Categoria serviciului spune ce s-a vândut; când e goală — și e
            // goală la o treime din servicii — rămâne codul, care știe: „h” e
            // hotel, „Pc” e pachet.
            $product = $services[$category] ?? null;
        }

        if ($product === null || $product === '') {
            $product = $codes[$code] ?? ($services['default'] ?? $fallbackProduct);
        }

        return [$channel, (string) $product];
    }

    /**
     * Serviciile facturate ale anului, cu departamentul comenzii și categoria
     * lor, aduse în lei la cursul de pe serviciu.
     *
     * Luna e a facturii, nu a călătoriei: raportul vorbește despre ce s-a
     * vândut în lună, ca și cel din eTrip.
     */
    private function sql(): string
    {
        return <<<'SQL'
            select coalesce(d.name, '') as department,
                   coalesce(s.serviceCfgCat, '') as category,
                   coalesce(s.serviceCode, '') as code,
                   month(coalesce(ci.invoiceDate, s.startDate)) as month,
                   count(*) as services,
                   sum(o.offerTotal * coalesce(nullif(o.currencyRate, 0), 1)) as net,
                   sum((o.offerTotal - o.supplierValue - coalesce(o.supplierVat, 0)) * coalesce(nullif(o.currencyRate, 0), 1)) as margin
            from offers o
            join services s on s.id = o.idService
            left join orders r on r.id = s.idOrder
            left join departments d on d.id = r.idDepartment
            left join clientInvoices ci on ci.id = s.idRelevantInvoice
            where coalesce(ci.invoiceDate, s.startDate) >= ?
              and coalesce(ci.invoiceDate, s.startDate) < ?
            group by 1, 2, 3, 4
            SQL;
    }
}
