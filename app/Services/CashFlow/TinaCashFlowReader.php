<?php

namespace App\Services\CashFlow;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Banii pe care îi au de dat firmele, din Tina.
 *
 * Business-ul corporate se vinde și se facturează în Tina, nu în eTrip, deci
 * prognoza de încasări nu-l vedea deloc: la 02.10.2026 sunt 1.672 de facturi
 * neîncasate, de 8,7 milioane. Fiecare factură intră la scadența ei; ce a
 * trecut de scadență se așază pe săptămâna curentă, fiindcă banii se cer acum,
 * nu la data la care trebuiau să vină.
 */
class TinaCashFlowReader
{
    /**
     * Încasările pe facturile corporate, pe săptămâni.
     *
     * Doar banii care chiar au intrat: ordinele de plată, cardurile și
     * chitanțele. Compensările, voucherele și plățile mutate de pe o factură
     * pe alta nu sunt bani noi, deci n-au ce căuta într-un flux de trezorerie.
     *
     * @return list<array{week: string, lei: float, receipts: int}>
     */
    public function receiptsByWeek(CarbonInterface $from, CarbonInterface $to): array
    {
        $types = (array) config('cashflow.tina.cash_doc_types', ['paymentOrder', 'receipt', 'creditcreditBt', 'cardEuropeBank', 'creditCardsodexo', 'cardEdenred']);

        $rows = DB::connection((string) config('cashflow.tina.connection', 'tina'))->select(sprintf(<<<'SQL'
            select date_format(date_sub(ch.docDate, interval weekday(ch.docDate) day), '%%Y-%%m-%%d') as week,
                   count(*) as receipts,
                   sum(ch.value * coalesce(nullif(ch.currencyRate, 0), 1)) as lei
            from clientInvoiceCashings ch
            where ch.voidTime is null
              and ch.docDate >= ? and ch.docDate <= ?
              and ch.docType in (%s)
            group by 1
            SQL, implode(', ', array_fill(0, count($types), '?'))), [$from->toDateString(), $to->toDateString(), ...$types]);

        return array_map(fn ($row) => [
            'week' => (string) $row->week,
            'receipts' => (int) $row->receipts,
            'lei' => round((float) $row->lei, 2),
        ], $rows);
    }

    /**
     * Facturile emise clienților și neîncasate, cu scadența lor.
     *
     * @return list<array{id: int, number: string, client: string, currency: string, amount: float, rate: float, due: string, issued: string}>
     */
    public function openClientInvoices(CarbonInterface $from, CarbonInterface $to, float $minBalance = 0.5): array
    {
        $rows = DB::connection((string) config('cashflow.tina.connection', 'tina'))->select(<<<'SQL'
            select ci.id,
                   ci.invoiceNumber as number,
                   coalesce(nullif(trim(c.companyNameStd), ''), nullif(trim(concat_ws(' ', cu.firstNameStd, cu.lastNameStd)), ''), '') as client,
                   coalesce(nullif(ci.currency, ''), 'RON') as currency,
                   (ci.invoiceTotal - coalesce(ci.cashedTotal, 0)) as amount,
                   coalesce(nullif(ci.currencyRate, 0), 1) as rate,
                   coalesce(ci.dueDate, ci.invoiceDate) as due,
                   ci.invoiceDate as issued
            from clientInvoices ci
            left join corporates c on c.id = ci.idCorporate
            left join customers cu on cu.id = ci.idCustomer
            where coalesce(ci.temporary, 0) = 0
              and ci.voidTime is null
              and (ci.invoiceTotal - coalesce(ci.cashedTotal, 0)) > ?
              and coalesce(ci.dueDate, ci.invoiceDate) >= ?
              and coalesce(ci.dueDate, ci.invoiceDate) <= ?
            SQL, [$minBalance, $from->toDateString(), $to->toDateString()]);

        return array_map(fn ($row) => [
            'id' => (int) $row->id,
            'number' => (string) $row->number,
            'client' => (string) $row->client,
            'currency' => OmcCashFlowReader::currency((string) $row->currency),
            'amount' => round((float) $row->amount, 2),
            'rate' => round((float) $row->rate, 6),
            'due' => (string) $row->due,
            'issued' => (string) $row->issued,
        ], $rows);
    }
}
