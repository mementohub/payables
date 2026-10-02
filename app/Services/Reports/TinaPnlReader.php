<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Venitul și marja din Tina, pe canal de vânzare și categorie de produs.
 *
 * Tina e un ERP separat, ca eTrip, pentru business-ul corporate: bilete de
 * avion, cazări și evenimente vândute firmelor. Categoria de produs vine din
 * departamentul care răspunde de comandă („Corporate”, „Ticketing”, „Hotels”),
 * așa cum o știe Tina — nu dintr-o ghicitoare peste tipul serviciului. Când
 * departamentul e unul de vânzare (B2B, B2C), produsul se ia din serviciu:
 * un bilet rămâne Ticketing, o cazare rămâne Cazare.
 *
 * Banii: fiecare serviciu ține valorile în moneda facturii, cu cursul zilei la
 * leu pe el (`invoiceCurrencyRate`). Venitul e ce plătește clientul, marja e
 * ce rămâne peste prețul furnizorului — la bilete, taxele de aeroport intră în
 * amândouă, deci se sting între ele.
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
     * Departamentul care răspunde de comandă decide. Departamentele de vânzare
     * (B2B, B2C) spun doar canalul, nu și ce s-a vândut, așa că produsul se ia
     * atunci din categoria serviciului.
     *
     * @param  array<string, array{product?: ?string, channel?: string}>  $map
     * @param  array<string, string>  $services
     * @return array{0: string, 1: string}
     */
    public function place(string $department, string $category, array $map, array $services, string $fallbackChannel, string $fallbackProduct): array
    {
        $rules = $map[$department] ?? [];
        $channel = (string) ($rules['channel'] ?? $fallbackChannel);
        $product = $rules['product'] ?? null;

        if ($product === null || $product === '') {
            $product = $services[$category] ?? ($services['default'] ?? $fallbackProduct);
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
                   month(coalesce(ci.invoiceDate, s.startDate)) as month,
                   count(*) as services,
                   sum(coalesce(s.invoiceTotal, 0) * coalesce(nullif(s.invoiceCurrencyRate, 0), 1)) as net,
                   sum((coalesce(s.invoiceTotal, 0) - coalesce(s.invoiceServicePriceValue, 0)) * coalesce(nullif(s.invoiceCurrencyRate, 0), 1)) as margin
            from services s
            left join orders o on o.id = s.idOrder
            left join departments d on d.id = o.idDepartment
            left join clientInvoices ci on ci.id = s.idRelevantInvoice
            where coalesce(ci.invoiceDate, s.startDate) >= ?
              and coalesce(ci.invoiceDate, s.startDate) < ?
              and coalesce(s.invoiceTotal, 0) <> 0
            group by 1, 2, 3
            SQL;
    }
}
