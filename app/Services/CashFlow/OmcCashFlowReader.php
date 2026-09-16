<?php

namespace App\Services\CashFlow;

use App\Services\Omc\OmcReader;
use Carbon\CarbonInterface;

/**
 * Read-only OMC queries behind the cash-flow report: the money that actually
 * moved (bank and cash documents), the supplier invoices still open and the
 * supplier-invoice lines the OPEX averages are taken from.
 */
class OmcCashFlowReader
{
    public function __construct(private OmcReader $omc) {}

    public function label(): string
    {
        return $this->omc->label();
    }

    /**
     * Receipts and payments per day and currency, in the document currency
     * and in lei, without the moves between the company's own accounts.
     *
     * @return list<array{day: string, kind: string, currency: string, amount: float, lei: float}>
     */
    public function dailyFlows(CarbonInterface $from, CarbonInterface $to): array
    {
        $receipts = (array) config('cashflow.omc.receipt_tip_doc', []);
        $payments = (array) config('cashflow.omc.payment_tip_doc', []);
        $types = [...$receipts, ...$payments];

        if ($types === []) {
            return [];
        }

        $rows = $this->omc->connection()->select(sprintf(<<<'SQL'
            select d.data_doc::date as day,
                   case when d.tip_doc in (%1$s) then 'in' else 'out' end as kind,
                   d.moneda as currency,
                   sum(d.val_mon)::numeric(20,2) as amount,
                   sum(case when d.moneda = 'Lei' then d.val_mon else d.val_mon * coalesce(nullif(d.curs, 0), 1) end)::numeric(20,2) as lei
            from doc d
            where d.data_doc >= ?::date
              and d.data_doc < ?::date
              and d.tip_doc in (%2$s)
              and d.data_anulare is null
            group by 1, 2, 3
            order by 1, 2, 3
            SQL, $this->quoted($receipts), $this->quoted($types)),
            [$from->toDateString(), $to->toDateString()]);

        return array_map(fn ($row) => [
            'day' => (string) $row->day,
            'kind' => (string) $row->kind,
            'currency' => self::currency((string) $row->currency),
            'amount' => (float) $row->amount,
            'lei' => (float) $row->lei,
        ], $rows);
    }

    /**
     * Supplier invoices not fully paid, by due date and currency.
     *
     * @return list<array{due: string, currency: string, amount: float, lei: float, invoices: int}>
     */
    public function openSupplierInvoices(CarbonInterface $since): array
    {
        $types = (array) config('cashflow.omc.supplier_tip_doc', ['FactFI', 'FactFE']);

        $rows = $this->omc->connection()->select(sprintf(<<<'SQL'
            select coalesce(d.data_scadenta, d.data_doc)::date as due,
                   d.moneda as currency,
                   sum(%2$s)::numeric(20,2) as amount,
                   sum((%2$s) * (case when d.moneda = 'Lei' then 1 else coalesce(nullif(d.curs, 0), 1) end))::numeric(20,2) as lei,
                   count(*) as invoices
            from doc d
            where d.tip_doc in (%1$s)
              and d.data_doc >= ?::date
              and d.data_anulare is null
              and (%2$s) > 0.01
            group by 1, 2
            order by 1, 2
            SQL, $this->quoted($types), 'd.val_mon - coalesce(d.val_mon_pl, 0) - coalesce(d.val_mon_dimin_negru, 0)'),
            [$since->toDateString()]);

        return array_map(fn ($row) => [
            'due' => (string) $row->due,
            'currency' => self::currency((string) $row->currency),
            'amount' => (float) $row->amount,
            'lei' => (float) $row->lei,
            'invoices' => (int) $row->invoices,
        ], $rows);
    }

    /**
     * Monthly average (lei, VAT included) of the supplier-invoice lines per
     * synthetic account over the period.
     *
     * @return array<string, float>
     */
    public function monthlyAverageByAccount(CarbonInterface $from, CarbonInterface $to, int $months): array
    {
        $types = (array) config('cashflow.omc.supplier_tip_doc', ['FactFI', 'FactFE']);

        $rows = $this->omc->connection()->select(sprintf(<<<'SQL'
            select dp.conts,
                   sum(dp.cant * dp.pret * (1 + coalesce(dp.proc_tva, 0) / 100.0)
                       * (case when d.moneda = 'Lei' then 1 else coalesce(nullif(d.curs, 0), 1) end)) / %2$d as monthly
            from doc d
            join doc_poz dp on (dp.data_doc, dp.tip_doc, dp.nr_doc) = (d.data_doc, d.tip_doc, d.nr_doc)
            where d.tip_doc in (%1$s)
              and d.data_doc >= ?::date
              and d.data_doc < ?::date
              and d.data_anulare is null
              and dp.conts is not null
            group by dp.conts
            SQL, $this->quoted($types), max(1, $months)),
            [$from->toDateString(), $to->toDateString()]);

        $averages = [];

        foreach ($rows as $row) {
            $averages[trim((string) $row->conts)] = round((float) $row->monthly, 2);
        }

        return $averages;
    }

    public static function currency(string $moneda): string
    {
        $currency = strtoupper(trim($moneda));

        return in_array($currency, ['LEI', 'RON', ''], true) ? 'RON' : $currency;
    }

    /**
     * @param  list<string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $value) => "'".str_replace("'", "''", $value)."'", $values));
    }
}
