<?php

namespace App\Services\CashFlow;

use App\Services\Omc\OmcReader;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Read-only OMC queries behind the cash-flow report: the treasury position
 * (month-end balances rolled forward with the bank and cash documents), the
 * money that actually moved, the supplier invoices still open, and the
 * supplier-invoice lines and ledger postings the OPEX averages come from.
 */
class OmcCashFlowReader
{
    public const GROUP_INTERNAL = 'internal';

    public const GROUP_PARTNER = 'partner';

    public const GROUP_SALARIES = 'salaries';

    public const GROUP_OTHER = 'other';

    public function __construct(private OmcReader $omc) {}

    public function label(): string
    {
        return $this->omc->label();
    }

    /**
     * The last closed month-end with bank balances in eu_banca_sold.
     */
    public function monthEndAnchor(CarbonInterface $today): ?CarbonImmutable
    {
        $row = $this->omc->connection()->selectOne(<<<'SQL'
            select max(s.data_sold) as data_sold
            from eu_banca_sold s
            where s.data_sold <= ?::date
              and s.data_sold = (date_trunc('month', s.data_sold) + interval '1 month - 1 day')::date
            SQL, [$today->toDateString()]);

        return $row && $row->data_sold !== null ? CarbonImmutable::parse((string) $row->data_sold) : null;
    }

    /**
     * The treasury position is always stated at the end of yesterday. OMC
     * saves the bank, cash and 5081 balances only at a month-end closing, so
     * each of the three starts from its own last closed balance on or before
     * that day and is rolled with the documents in between. When OMC holds
     * no closed balance at all before yesterday, the latest one it does hold
     * is used instead, so the position shows the most recent figures rather
     * than nothing.
     *
     * @return array{as_of: CarbonImmutable, bank: ?CarbonImmutable, cash: ?CarbonImmutable, deposits: ?CarbonImmutable, fallback: bool}
     */
    public function balanceAnchors(CarbonInterface $today): array
    {
        $asOf = CarbonImmutable::instance($today)->subDay()->startOfDay();
        $deposit = (string) config('cashflow.omc.deposit_account', '5081');
        $day = $asOf->toDateString();

        $row = $this->omc->connection()->selectOne(<<<'SQL'
            select (select max(s.data_sold) from eu_banca_sold s where s.data_sold <= ?::date) as bank,
                   (select max(s.data_sold) from casa_sold s where s.data_sold <= ?::date) as cash,
                   (select max(s.data_sold) from conta_sold s where s.conts = ? and s.data_sold <= ?::date) as deposits,
                   (select max(s.data_sold) from eu_banca_sold s) as any_bank,
                   (select max(s.data_sold) from casa_sold s) as any_cash,
                   (select max(s.data_sold) from conta_sold s where s.conts = ?) as any_deposits
            SQL, [$day, $day, $deposit, $day, $deposit]);

        $parse = fn ($value) => $value !== null ? CarbonImmutable::parse((string) $value) : null;
        $fallback = $row?->bank === null && $row?->any_bank !== null;

        return [
            'as_of' => $fallback ? $parse($row->any_bank) : $asOf,
            'bank' => $parse($row?->bank ?? $row?->any_bank),
            'cash' => $parse($row?->cash ?? $row?->any_cash),
            'deposits' => $parse($row?->deposits ?? $row?->any_deposits),
            'fallback' => $fallback,
        ];
    }

    /**
     * The BNR rate of each currency on the given day, as OMC records it: the
     * latest quote on or before that day. The treasury position is converted
     * at these, because it states what the accounts held on a date.
     *
     * @return array<string, float>
     */
    public function ratesAt(CarbonInterface $at): array
    {
        try {
            $rows = $this->omc->connection()->select(<<<'SQL'
                select distinct on (c.moneda) c.moneda, c.curs_bnr
                from curs c
                where c.data_curs <= ?::date and c.curs_bnr > 0
                order by c.moneda, c.data_curs desc
                SQL, [$at->toDateString()]);
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $rates = ['RON' => 1.0];

        foreach ($rows as $row) {
            $rates[self::currency((string) $row->moneda)] = (float) $row->curs_bnr;
        }

        return $rates;
    }

    /**
     * Treasury position per currency at the end of the given day: the last
     * closed balance of every bank account, cash desk and 5081 deposit
     * account on or before its anchor, rolled with the bank and cash
     * documents dated after that anchor up to and including $asOf. Deposit
     * moves, the OP_PL and OP_INC documents whose counterpart is 5081, are
     * reported apart so the deposits roll on their own.
     *
     * @param  array{bank: ?CarbonInterface, cash: ?CarbonInterface, deposits: ?CarbonInterface}  $anchors
     * @return list<array{currency: string, bank_open: float, bank_in: float, bank_out: float, cash_open: float, cash_in: float, cash_out: float, deposits_open: float, deposits_open_lei: float, deposits_change: float}>
     */
    public function openingPosition(array $anchors, CarbonInterface $asOf): array
    {
        $connection = $this->omc->connection();
        $deposit = (string) config('cashflow.omc.deposit_account', '5081');
        $to = CarbonImmutable::instance($asOf)->toDateString();
        $balancesAt = fn (?CarbonInterface $anchor) => $anchor?->toDateString() ?? '1900-01-01';
        // Without a closed balance there is nothing to roll from, so the
        // section opens at zero and its documents are left out too.
        $movesAfter = fn (?CarbonInterface $anchor) => $anchor?->toDateString() ?? $to;

        $rows = $connection->select(<<<'SQL'
            with acc as (
                select banca, cont_banca, moneda from eu_banca where not coalesce(discontinued, false)
            ),
            s1 as (
                select distinct on (s.banca, s.cont_banca) s.banca, s.cont_banca,
                       coalesce(s.sold_banca_db, 0) - coalesce(s.sold_banca_cr, 0) as s
                from eu_banca_sold s
                where s.data_sold <= ?::date
                order by s.banca, s.cont_banca, s.data_sold desc
            ),
            mv as (
                select d.banca_eu as banca, d.cont_banca_eu as cont_banca,
                       sum(case when t.incasare_b then d.val_mon else 0 end) as inc,
                       sum(case when t.plata_b then d.val_mon else 0 end) as pl
                from doc d
                join tip_doc t on t.tip_doc = d.tip_doc
                where d.data_doc > ?::date and d.data_doc <= ?::date
                  and (t.incasare_b or t.plata_b)
                  and d.data_anulare is null
                group by 1, 2
            ),
            bank as (
                select a.moneda,
                       sum(coalesce(s1.s, 0)) as open_m,
                       sum(coalesce(mv.inc, 0)) as inc,
                       sum(coalesce(mv.pl, 0)) as pl
                from acc a
                left join s1 on s1.banca = a.banca and s1.cont_banca = a.cont_banca
                left join mv on mv.banca = a.banca and mv.cont_banca = a.cont_banca
                group by a.moneda
            ),
            cash as (
                select c.moneda, sum(coalesce(cs.sold_casa_db, 0)) as casa_m
                from (
                    select distinct on (s.casa, s.moneda) s.casa, s.moneda, s.sold_casa_db
                    from casa_sold s
                    where s.data_sold <= ?::date
                    order by s.casa, s.moneda, s.data_sold desc
                ) cs
                join casa c on c.casa = cs.casa and c.moneda = cs.moneda
                group by 1
            ),
            cashmv as (
                select d.moneda,
                       sum(case when t.incasare_c then d.val_mon else 0 end) as inc,
                       sum(case when t.plata_c then d.val_mon else 0 end) as pl
                from doc d
                join tip_doc t on t.tip_doc = d.tip_doc
                where d.data_doc > ?::date and d.data_doc <= ?::date
                  and (t.incasare_c or t.plata_c)
                  and d.data_anulare is null
                group by 1
            ),
            dep as (
                select d.moneda,
                       sum(case when d.tip_doc = 'OP_PL' then d.val_mon else -d.val_mon end) as dep_net
                from doc d
                where d.data_doc > ?::date and d.data_doc <= ?::date
                  and d.tip_doc in ('OP_PL', 'OP_INC')
                  and d.conts_direct_coresp = ?
                  and d.data_anulare is null
                group by 1
            ),
            currencies as (
                select moneda from bank
                union select moneda from cash
                union select moneda from cashmv
                union select moneda from dep
            )
            select cu.moneda,
                   coalesce(b.open_m, 0) as bank_open, coalesce(b.inc, 0) as bank_in, coalesce(b.pl, 0) as bank_out,
                   coalesce(c.casa_m, 0) as cash_open, coalesce(cm.inc, 0) as cash_in, coalesce(cm.pl, 0) as cash_out,
                   coalesce(dp.dep_net, 0) as deposits_change
            from currencies cu
            left join bank b on b.moneda = cu.moneda
            left join cash c on c.moneda = cu.moneda
            left join cashmv cm on cm.moneda = cu.moneda
            left join dep dp on dp.moneda = cu.moneda
            order by 1
            SQL, [
            $balancesAt($anchors['bank']), $movesAfter($anchors['bank']), $to,
            $balancesAt($anchors['cash']), $movesAfter($anchors['cash']), $to,
            $movesAfter($anchors['deposits']), $to, $deposit,
        ]);

        $deposits = $connection->select(<<<'SQL'
            select coalesce(c.moneda, 'Lei') as moneda,
                   sum(cs.sold_db - coalesce(cs.sold_cr, 0)) as sold,
                   sum(cs.sold_db_lei - coalesce(cs.sold_cr_lei, 0)) as sold_lei
            from (
                select distinct on (s.conts, s.conta) s.conts, s.conta, s.sold_db, s.sold_cr, s.sold_db_lei, s.sold_cr_lei
                from conta_sold s
                where s.conts = ? and s.data_sold <= ?::date
                order by s.conts, s.conta, s.data_sold desc
            ) cs
            left join conta c on c.conts = cs.conts and c.conta = cs.conta
            group by 1
            SQL, [$deposit, $balancesAt($anchors['deposits'])]);

        $position = [];

        foreach ($rows as $row) {
            $currency = self::currency((string) $row->moneda);
            $position[$currency] = [
                'currency' => $currency,
                'bank_open' => round((float) $row->bank_open, 2),
                'bank_in' => round((float) $row->bank_in, 2),
                'bank_out' => round((float) $row->bank_out, 2),
                'cash_open' => round((float) $row->cash_open, 2),
                'cash_in' => round((float) $row->cash_in, 2),
                'cash_out' => round((float) $row->cash_out, 2),
                'deposits_open' => 0.0,
                'deposits_open_lei' => 0.0,
                'deposits_change' => round((float) $row->deposits_change, 2),
            ];
        }

        foreach ($deposits as $row) {
            $currency = self::currency((string) $row->moneda);
            $position[$currency] ??= ['currency' => $currency, 'bank_open' => 0.0, 'bank_in' => 0.0, 'bank_out' => 0.0, 'cash_open' => 0.0, 'cash_in' => 0.0, 'cash_out' => 0.0, 'deposits_open' => 0.0, 'deposits_open_lei' => 0.0, 'deposits_change' => 0.0];
            $position[$currency]['deposits_open'] += round((float) $row->sold, 2);
            $position[$currency]['deposits_open_lei'] += round((float) $row->sold_lei, 2);
        }

        ksort($position);

        return array_values($position);
    }

    /**
     * Receipts and payments per day and currency (document currency and
     * lei), recognised by the tip_doc flags, classed as internal (moves
     * between the company's own accounts: cash to bank, deposits, credit
     * lines), partner (a partner on the document), salaries / taxes (by
     * the counterpart account) or other.
     *
     * @return list<array{day: string, kind: string, group: string, currency: string, amount: float, lei: float}>
     */
    public function dailyFlows(CarbonInterface $from, CarbonInterface $to): array
    {
        $internalTypes = (array) config('cashflow.omc.internal_tip_doc', []);
        $internal = (array) config('cashflow.omc.internal_coresp', []);
        $salaries = (array) config('cashflow.omc.salary_tax_coresp', []);

        $rows = $this->omc->connection()->select(sprintf(<<<'SQL'
            select d.data_doc::date as day,
                   case when t.incasare_b or t.incasare_c then 'in' else 'out' end as kind,
                   case when %1$s then 'internal'
                        when d.partener is not null then 'partner'
                        when %2$s then 'salaries'
                        else 'other' end as flow_group,
                   d.moneda as currency,
                   sum(d.val_mon)::numeric(20,2) as amount,
                   sum(case when d.moneda = 'Lei' then d.val_mon else d.val_mon * coalesce(nullif(d.curs, 0), 1) end)::numeric(20,2) as lei
            from doc d
            join tip_doc t on t.tip_doc = d.tip_doc
            where d.data_doc >= ?::date
              and d.data_doc < ?::date
              and (t.incasare_b or t.plata_b or t.incasare_c or t.plata_c)
              and d.data_anulare is null
            group by 1, 2, 3, 4
            order by 1, 2, 3, 4
            SQL, $this->groupCondition($internalTypes, $internal), $this->groupCondition([], $salaries)),
            [$from->toDateString(), $to->toDateString()]);

        return array_map(fn ($row) => [
            'day' => (string) $row->day,
            'kind' => (string) $row->kind,
            'group' => (string) $row->flow_group,
            'currency' => self::currency((string) $row->currency),
            'amount' => (float) $row->amount,
            'lei' => (float) $row->lei,
        ], $rows);
    }

    /**
     * Bank and cash documents per week, partner and counterpart account (in
     * lei), without the moves between the company's own accounts: what the
     * actual cash flow is classified line by line from.
     *
     * @return list<array{week: string, kind: string, partner: ?string, coresp: string, lei: float}>
     */
    public function weeklyFlowsByPartner(CarbonInterface $from, CarbonInterface $to): array
    {
        $internalTypes = (array) config('cashflow.omc.internal_tip_doc', []);
        $internal = (array) config('cashflow.omc.internal_coresp', []);

        $rows = $this->omc->connection()->select(sprintf(<<<'SQL'
            select (date_trunc('week', d.data_doc))::date as week,
                   case when t.incasare_b or t.incasare_c then 'in' else 'out' end as kind,
                   d.partener as partner,
                   coalesce(trim(d.conts_direct_coresp), '') as coresp,
                   sum(case when d.moneda = 'Lei' then d.val_mon else d.val_mon * coalesce(nullif(d.curs, 0), 1) end)::numeric(20,2) as lei
            from doc d
            join tip_doc t on t.tip_doc = d.tip_doc
            where d.data_doc >= ?::date
              and d.data_doc < ?::date
              and (t.incasare_b or t.plata_b or t.incasare_c or t.plata_c)
              and d.data_anulare is null
              and not coalesce(%1$s, false)
            group by 1, 2, 3, 4
            SQL, $this->groupCondition($internalTypes, $internal)),
            [$from->toDateString(), $to->toDateString()]);

        return array_map(fn ($row) => [
            'week' => (string) $row->week,
            'kind' => (string) $row->kind,
            'partner' => $row->partner !== null ? (string) $row->partner : null,
            'coresp' => (string) $row->coresp,
            'lei' => (float) $row->lei,
        ], $rows);
    }

    /**
     * The bank and cash documents behind one classified flow: one partner
     * (or none) and counterpart account, receipts or payments, in a range.
     *
     * @return list<array{data_doc: string, tip_doc: string, nr_doc: string, currency: string, amount: float, lei: float, note: ?string}>
     */
    public function treasuryDocuments(CarbonInterface $from, CarbonInterface $to, string $direction, ?string $partner, string $coresp, int $limit = 300): array
    {
        $internalTypes = (array) config('cashflow.omc.internal_tip_doc', []);
        $internal = (array) config('cashflow.omc.internal_coresp', []);

        $rows = $this->omc->connection()->select(sprintf(<<<'SQL'
            select d.data_doc::date as data_doc, d.tip_doc, d.nr_doc, d.moneda as currency,
                   d.val_mon::numeric(20,2) as amount,
                   (case when d.moneda = 'Lei' then d.val_mon else d.val_mon * coalesce(nullif(d.curs, 0), 1) end)::numeric(20,2) as lei,
                   nullif(trim(d.obs_txt), '') as note
            from doc d
            join tip_doc t on t.tip_doc = d.tip_doc
            where d.data_doc >= ?::date
              and d.data_doc < ?::date
              and (t.incasare_b or t.plata_b or t.incasare_c or t.plata_c)
              and (t.incasare_b or t.incasare_c) = ?
              and d.data_anulare is null
              and not coalesce(%1$s, false)
              and %2$s
              and coalesce(trim(d.conts_direct_coresp), '') = ?
            order by abs(d.val_mon) desc
            limit %3$d
            SQL, $this->groupCondition($internalTypes, $internal), $partner === null ? 'd.partener is null' : 'd.partener = ?', max(1, $limit)),
            array_values(array_filter([$from->toDateString(), $to->toDateString(), $direction === 'in', $partner, $coresp], fn ($value) => $value !== null)));

        return array_map(fn ($row) => [
            'data_doc' => (string) $row->data_doc,
            'tip_doc' => (string) $row->tip_doc,
            'nr_doc' => (string) $row->nr_doc,
            'currency' => self::currency((string) $row->currency),
            'amount' => (float) $row->amount,
            'lei' => (float) $row->lei,
            'note' => $row->note !== null ? (string) $row->note : null,
        ], $rows);
    }

    /**
     * The account each supplier's invoices mostly go to (by value), e.g.
     * 471 for tourism services, 623 for marketing, 612 for rent.
     *
     * @return array<string, string>
     */
    public function partnerMainAccounts(CarbonInterface $from, CarbonInterface $to): array
    {
        $types = (array) config('cashflow.omc.supplier_tip_doc', ['FactFI', 'FactFE']);

        $rows = $this->omc->connection()->select(sprintf(<<<'SQL'
            with acc as (
                select d.partener, trim(dp.conts) as conts, sum(abs(dp.cant * dp.pret)) as value
                from doc d
                join doc_poz dp on (dp.data_doc, dp.tip_doc, dp.nr_doc) = (d.data_doc, d.tip_doc, d.nr_doc)
                where d.tip_doc in (%1$s)
                  and d.data_doc >= ?::date
                  and d.data_doc < ?::date
                  and d.data_anulare is null
                  and d.partener is not null
                  and dp.conts is not null
                group by 1, 2
            )
            select distinct on (partener) partener, conts
            from acc
            order by partener, value desc
            SQL, $this->quoted($types)),
            [$from->toDateString(), $to->toDateString()]);

        $accounts = [];

        foreach ($rows as $row) {
            $accounts[(string) $row->partener] = (string) $row->conts;
        }

        return $accounts;
    }

    /**
     * The treasury position at every closed month-end in the range: bank
     * accounts, cash desks and deposits per currency, with the BNR rate of
     * that day when OMC has it.
     *
     * @return list<array{date: string, bank: array<string, float>, cash: array<string, float>, deposits: array<string, float>, rates: array<string, float>}>
     */
    public function monthEndPositions(CarbonInterface $from, CarbonInterface $to): array
    {
        $connection = $this->omc->connection();
        $deposit = (string) config('cashflow.omc.deposit_account', '5081');
        $range = [$from->toDateString(), $to->toDateString()];
        $monthEnd = "s.data_sold = (date_trunc('month', s.data_sold) + interval '1 month - 1 day')::date";

        $positions = [];
        $put = function (string $date, string $section, string $currency, float $amount) use (&$positions): void {
            $positions[$date] ??= ['date' => $date, 'bank' => [], 'cash' => [], 'deposits' => [], 'rates' => []];
            $positions[$date][$section][$currency] = round(($positions[$date][$section][$currency] ?? 0.0) + $amount, 2);
        };

        foreach ($connection->select(<<<SQL
            select s.data_sold, a.moneda, sum(s.sold_banca_db - s.sold_banca_cr) as amount
            from eu_banca_sold s
            join eu_banca a on a.banca = s.banca and a.cont_banca = s.cont_banca
            where s.data_sold >= ?::date and s.data_sold <= ?::date and {$monthEnd}
            group by 1, 2
            SQL, $range) as $row) {
            $put((string) $row->data_sold, 'bank', self::currency((string) $row->moneda), (float) $row->amount);
        }

        foreach ($connection->select(<<<SQL
            select s.data_sold, s.moneda, sum(s.sold_casa_db) as amount
            from casa_sold s
            where s.data_sold >= ?::date and s.data_sold <= ?::date and {$monthEnd}
            group by 1, 2
            SQL, $range) as $row) {
            $put((string) $row->data_sold, 'cash', self::currency((string) $row->moneda), (float) $row->amount);
        }

        foreach ($connection->select(<<<SQL
            select s.data_sold, coalesce(c.moneda, 'Lei') as moneda, sum(s.sold_db - coalesce(s.sold_cr, 0)) as amount
            from conta_sold s
            left join conta c on c.conts = s.conts and c.conta = s.conta
            where s.conts = ? and s.data_sold >= ?::date and s.data_sold <= ?::date and {$monthEnd}
            group by 1, 2
            SQL, [$deposit, ...$range]) as $row) {
            $put((string) $row->data_sold, 'deposits', self::currency((string) $row->moneda), (float) $row->amount);
        }

        if ($positions !== []) {
            try {
                foreach ($connection->select(<<<'SQL'
                    select c.data_curs, c.moneda, c.curs_bnr
                    from curs c
                    where c.data_curs >= ?::date and c.data_curs <= ?::date and c.curs_bnr > 0
                    SQL, $range) as $row) {
                    $date = (string) $row->data_curs;

                    if (isset($positions[$date])) {
                        $positions[$date]['rates'][self::currency((string) $row->moneda)] = (float) $row->curs_bnr;
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        ksort($positions);

        return array_values($positions);
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
     * Supplier invoices not fully paid, one by one, with what is still open
     * on each (in its currency and in lei at its own rate).
     *
     * @return list<array{data_doc: string, tip_doc: string, nr_doc: string, partner: ?string, due: string, currency: string, amount: float, lei: float}>
     */
    public function openSupplierInvoiceList(CarbonInterface $since): array
    {
        $types = (array) config('cashflow.omc.supplier_tip_doc', ['FactFI', 'FactFE']);

        $rows = $this->omc->connection()->select(sprintf(<<<'SQL'
            select d.data_doc::date as data_doc, d.tip_doc, d.nr_doc, d.partener as partner,
                   coalesce(d.data_scadenta, d.data_doc)::date as due,
                   d.moneda as currency,
                   (%2$s)::numeric(20,2) as amount,
                   ((%2$s) * (case when d.moneda = 'Lei' then 1 else coalesce(nullif(d.curs, 0), 1) end))::numeric(20,2) as lei
            from doc d
            where d.tip_doc in (%1$s)
              and d.data_doc >= ?::date
              and d.data_anulare is null
              and (%2$s) > 0.01
            order by 5, 4, 1
            SQL, $this->quoted($types), 'd.val_mon - coalesce(d.val_mon_pl, 0) - coalesce(d.val_mon_dimin_negru, 0)'),
            [$since->toDateString()]);

        return array_map(fn ($row) => [
            'data_doc' => (string) $row->data_doc,
            'tip_doc' => (string) $row->tip_doc,
            'nr_doc' => (string) $row->nr_doc,
            'partner' => $row->partner !== null ? (string) $row->partner : null,
            'due' => (string) $row->due,
            'currency' => self::currency((string) $row->currency),
            'amount' => (float) $row->amount,
            'lei' => (float) $row->lei,
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

    /**
     * Monthly average (lei) of the ledger postings paid from the bank or
     * the cash desk, per debit account: salaries, contributions, taxes,
     * bank fees, dividends. One scan of reg_jurnal, so it is called once.
     *
     * @return array<string, float>
     */
    public function monthlyLedgerByAccount(CarbonInterface $from, CarbonInterface $to, int $months): array
    {
        $treasury = (array) config('cashflow.omc.treasury_prefixes', ['512', '531']);
        $condition = implode(' or ', array_map(fn (string $prefix) => sprintf("j.conts_cr like '%s%%'", str_replace("'", "''", $prefix)), $treasury)) ?: 'false';

        $rows = $this->omc->connection()->select(sprintf(<<<'SQL'
            select j.conts_db as account, sum(j.valoare_lei) / %2$d as monthly
            from reg_jurnal j
            where j.data_reg_jurnal >= ?::date
              and j.data_reg_jurnal < ?::date
              and (%1$s)
            group by j.conts_db
            SQL, $condition, max(1, $months)),
            [$from->toDateString(), $to->toDateString()]);

        $averages = [];

        foreach ($rows as $row) {
            $averages[trim((string) $row->account)] = round((float) $row->monthly, 2);
        }

        return $averages;
    }

    public static function currency(string $moneda): string
    {
        $currency = strtoupper(trim($moneda));

        return in_array($currency, ['LEI', 'RON', ''], true) ? 'RON' : $currency;
    }

    /**
     * SQL condition for "document type in the list or counterpart account
     * starting with one of the prefixes"; false when both are empty.
     *
     * @param  list<string>  $types
     * @param  list<string>  $prefixes
     */
    private function groupCondition(array $types, array $prefixes): string
    {
        $parts = [];

        if ($types !== []) {
            $parts[] = sprintf('d.tip_doc in (%s)', $this->quoted($types));
        }

        foreach ($prefixes as $prefix) {
            $parts[] = sprintf("d.conts_direct_coresp like '%s%%'", str_replace("'", "''", $prefix));
        }

        return $parts === [] ? 'false' : '('.implode(' or ', $parts).')';
    }

    /**
     * @param  list<string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $value) => "'".str_replace("'", "''", $value)."'", $values));
    }
}
