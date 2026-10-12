<?php

namespace App\Services\Suppliers;

use App\Models\Invoice;
use App\Models\InvoiceSource;
use App\Models\Partner;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cine stă, de fapt, în spatele unei facturi refacturate.
 *
 * Trei case din grup cumpără din piață și ne refacturează: Memento Air
 * zborurile, Memento International cazările, Memento Management & Logistics
 * chiriile magazinelor. Pe factura primită apare numele lor; compania
 * aeriană, hotelul sau mall-ul se văd abia în baza casei care a cumpărat.
 *
 * Puntea între baze e numărul facturii: ce noi am primit cu numărul X e,
 * în baza lor, factura emisă către noi cu același număr. De acolo, fiecare
 * casă lasă altă urmă — vezi `config/omc.php`, cheia `resellers`.
 */
class SupplierTrace
{
    /** Cum ne cheamă pe noi, ca să nu ne socotim propriul furnizor. */
    private const OURS = ['9617078', 'RO9617078'];

    /**
     * Caută furnizorii din spatele facturilor unei case, pe o fereastră de timp.
     *
     * @return array{invoices: int, matched: int, sources: int, skipped: string|null}
     */
    public function trace(string $cui, Carbon $from, Carbon $to): array
    {
        $house = (array) config('omc.resellers.'.$cui, []);

        if ($house === []) {
            return ['invoices' => 0, 'matched' => 0, 'sources' => 0, 'skipped' => 'casa '.$cui.' nu e configurată'];
        }

        $partnerId = Partner::query()->where('cui', $cui)->value('id');

        if ($partnerId === null) {
            return ['invoices' => 0, 'matched' => 0, 'sources' => 0, 'skipped' => 'partenerul '.$cui.' nu e în aplicație'];
        }

        $invoices = Invoice::query()
            ->where('partner_id', $partnerId)
            ->where('partener_type', 'furnizor')
            ->whereNull('omc_removed_at')
            ->whereBetween('data_doc', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'nr_doc', 'data_doc', 'val_mon', 'curs']);

        if ($invoices->isEmpty()) {
            return ['invoices' => 0, 'matched' => 0, 'sources' => 0, 'skipped' => null];
        }

        $db = DB::connection($house['connection']);
        $us = $this->ourName($db);

        if ($us === null) {
            return ['invoices' => $invoices->count(), 'matched' => 0, 'sources' => 0, 'skipped' => 'în baza casei nu apare Christian Tour'];
        }

        // Liniile cu care casa ne-a facturat: de aici pornește totul.
        $lines = $this->salesLines($db, $us, $from, $to);

        $sources = match ($house['basis']) {
            InvoiceSource::BASIS_ORDER => $this->byOrder($db, $invoices, $lines),
            InvoiceSource::BASIS_BOOKING => $this->byKey($db, $invoices, $lines, 'com_int', InvoiceSource::BASIS_BOOKING, $from, $to),
            InvoiceSource::BASIS_ARTICLE => $this->byKey($db, $invoices, $lines, 'articol', InvoiceSource::BASIS_ARTICLE, $from, $to),
            default => [],
        };

        $this->store($invoices->pluck('id')->all(), $sources);

        return [
            'invoices' => $invoices->count(),
            'matched' => count(array_unique(array_column($sources, 'invoice_id'))),
            'sources' => count($sources),
            'skipped' => null,
        ];
    }

    /**
     * Numele sub care stăm noi în baza casei: „CHRISTIAN76 TOUR SRL” într-una,
     * „CHRISTIAN'76 TOUR S.R.L.” în alta. Se caută după codul fiscal, nu după
     * nume, tocmai fiindcă numele se scrie cum apucă fiecare.
     */
    private function ourName(ConnectionInterface $db): ?string
    {
        $row = $db->selectOne(
            "select partener from partener where replace(upper(coalesce(cod_cci, '')), 'RO', '') = ? limit 1",
            [self::OURS[0]],
        );

        return $row?->partener;
    }

    /**
     * Liniile facturilor pe care casa ni le-a emis, cu comanda și articolul
     * fiecăreia. Facturile de întocmit (`FactINT`) rămân afară: poartă
     * același număr ca cea adevărată și ar dubla sumele.
     *
     * @return Collection<int, object>
     */
    private function salesLines(ConnectionInterface $db, string $us, Carbon $from, Carbon $to): Collection
    {
        return collect($db->select(<<<'SQL'
            select trim(d.nr_doc) as nr_doc,
                   nullif(trim(coalesce(p.com_int, '')), '') as com_int,
                   nullif(trim(coalesce(p.articol, '')), '') as articol,
                   to_char(d.data_doc, 'YYYY-MM') as month,
                   coalesce(p.cant, 0) * coalesce(p.pret, 0) * coalesce(d.curs, 1) as lei
            from doc_poz p
            join doc d on d.data_doc = p.data_doc and d.tip_doc = p.tip_doc and d.nr_doc = p.nr_doc
            join tip_doc t on t.tip_doc = d.tip_doc
            where d.partener = ? and t.grupa = 'client' and d.tip_doc <> 'FactINT'
              and d.data_doc between ? and ?
        SQL, [$us, $from->toDateString(), $to->toDateString()]));
    }

    /**
     * Memento Air: factura stă pe o comandă de zbor, comanda de zbor pe luna
     * de charter, iar pe lună stau facturile companiilor aeriene. Cum se
     * cumpără pe perioade și se vinde pe zboruri, furnizorii nu se pot pune
     * unul în dreptul altuia: se dau procentual, pe cât cântărește fiecare în
     * cheltuiala lunii.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @param  Collection<int, object>  $lines
     * @return list<array<string, mixed>>
     */
    private function byOrder(ConnectionInterface $db, Collection $invoices, Collection $lines): array
    {
        $orders = $lines->pluck('com_int')->filter()->unique()->values();

        if ($orders->isEmpty()) {
            return [];
        }

        $root = $this->roots($db, $orders);

        $mixes = [];
        $byInvoice = $lines->groupBy('nr_doc');
        $rows = [];

        foreach ($invoices as $invoice) {
            $own = $byInvoice->get(trim((string) $invoice->nr_doc));

            if ($own === null) {
                continue;
            }

            $total = (float) $own->sum('lei');

            if (abs($total) < 0.01) {
                continue;
            }

            // Cât cântărește fiecare lună de charter în factura asta.
            $weights = [];

            foreach ($own as $line) {
                $order = $root[$line->com_int] ?? $line->com_int;

                if ($order === null) {
                    continue;
                }

                $weights[$order] = ($weights[$order] ?? 0) + (float) $line->lei;
            }

            foreach ($weights as $order => $part) {
                $mixes[$order] ??= $this->orderMix($db, (string) $order);
                $mix = $mixes[$order];

                if ($mix['total'] <= 0) {
                    continue;
                }

                foreach ($mix['suppliers'] as $supplier) {
                    $share = ($part / $total) * ($supplier->lei / $mix['total']);

                    if ($share < 0.0005) {
                        continue;
                    }

                    $rows[] = [
                        'invoice_id' => $invoice->id,
                        'supplier_name' => mb_substr((string) $supplier->partener, 0, 200),
                        'supplier_tax_id' => $this->tax($supplier->cod_cci ?? null),
                        'basis' => InvoiceSource::BASIS_ORDER,
                        'reference' => mb_substr((string) $order, 0, 200),
                        'doc_ref' => null,
                        'doc_date' => null,
                        'share' => round($share, 6),
                        'val_lei' => round($this->lei($invoice) * $share, 2),
                        'exact' => false,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Comanda pe care stă cheltuiala, pentru fiecare comandă de pe vânzare.
     *
     * De obicei e comanda-părinte: zborul ține de luna lui. Unele comenzi de
     * zbor au rămas însă fără părinte, și atunci lanțul se rupea — factura
     * ieșea fără niciun furnizor. Pentru ele se caută luna de charter care
     * cuprinde data de început a zborului: tot acolo stă cheltuiala.
     *
     * @param  Collection<int, string>  $orders
     * @return array<string, string>
     */
    private function roots(ConnectionInterface $db, Collection $orders): array
    {
        $months = [];

        foreach ($db->select("select com_int, to_char(data_incep, 'YYYY-MM') as month
            from com_int where com_int ilike 'CHARTER%' and data_incep is not null") as $row) {
            $months[$row->month] ??= $row->com_int;
        }

        $root = [];

        foreach ($orders->chunk(500) as $chunk) {
            foreach ($db->select("select com_int, nullif(trim(com_int_sup), '') as sup,
                to_char(data_incep, 'YYYY-MM') as month
                from com_int where com_int in (".$this->marks($chunk).')',
                $chunk->values()->all(),
            ) as $row) {
                $root[$row->com_int] = $row->sup
                    ?? ($months[$row->month] ?? $row->com_int);
            }
        }

        return $root;
    }

    /**
     * Cheltuiala unei luni de charter, împărțită pe companii aeriene.
     *
     * @return array{total: float, suppliers: list<object>}
     */
    private function orderMix(ConnectionInterface $db, string $order): array
    {
        $suppliers = $db->select(<<<'SQL'
            select d.partener, max(pa.cod_cci) as cod_cci,
                   sum(coalesce(p.cant, 0) * coalesce(p.pret, 0) * coalesce(d.curs, 1)) as lei
            from doc_poz p
            join doc d on d.data_doc = p.data_doc and d.tip_doc = p.tip_doc and d.nr_doc = p.nr_doc
            join tip_doc t on t.tip_doc = d.tip_doc
            left join partener pa on pa.partener = d.partener
            left join com_int c on c.com_int = p.com_int
            where t.grupa = 'furnizor'
              and coalesce(nullif(trim(c.com_int_sup), ''), p.com_int) = ?
              and replace(upper(coalesce(pa.cod_cci, '')), 'RO', '') <> ?
            group by d.partener
            having sum(coalesce(p.cant, 0) * coalesce(p.pret, 0) * coalesce(d.curs, 1)) > 0
        SQL, [$order, self::OURS[0]]);

        return [
            'total' => (float) array_sum(array_map(fn ($s) => (float) $s->lei, $suppliers)),
            'suppliers' => $suppliers,
        ];
    }

    /**
     * Memento International și Memento M&L: aceeași cheie stă pe amândouă
     * fețele — numărul rezervării la una, codul articolului la cealaltă. Aici
     * se știe documentul furnizorului, nu doar numele lui.
     *
     * Când o cheie se împarte între mai multe facturi de-ale noastre, suma
     * furnizorului se împarte și ea, pe cât ține fiecare: altfel aceeași
     * cheltuială ar apărea întreagă de mai multe ori.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @param  Collection<int, object>  $lines
     * @return list<array<string, mixed>>
     */
    private function byKey(ConnectionInterface $db, Collection $invoices, Collection $lines, string $field, string $basis, Carbon $from, Carbon $to): array
    {
        $keyed = $lines->filter(fn ($line) => $line->{$field} !== null);

        if ($keyed->isEmpty()) {
            return [];
        }

        $keys = $keyed->pluck($field)->unique()->values();
        $window = $this->window($basis, $from, $to);
        $buys = $this->purchases($db, $field, $keys, $basis, $window);

        // Cât s-a vândut cu totul pe fiecare cheie — nu numai în luna asta.
        // O rezervare se poate factura în două luni; dacă numitorul ar fi doar
        // ce prindem acum, fiecare bucată ar primi mai mult decât i se cuvine
        // și factura ar ieși acoperită de două ori.
        $whole = $this->salesTotals($db, $this->ourName($db) ?? '', $field, $keys, $basis, $window);

        $byNumber = $invoices->keyBy(fn (Invoice $invoice) => trim((string) $invoice->nr_doc));
        $rows = [];

        foreach ($keyed->groupBy('nr_doc') as $number => $own) {
            $invoice = $byNumber->get((string) $number);

            if ($invoice === null) {
                continue;
            }

            $total = (float) $own->sum('lei');

            foreach ($own as $line) {
                $key = $this->key($basis, $line->{$field}, $line->month);
                $found = $buys[$key] ?? null;

                if ($found === null || ($whole[$key] ?? 0.0) <= 0) {
                    continue;
                }

                $part = (float) $line->lei / $whole[$key];

                foreach ($found as $buy) {
                    $lei = round((float) $buy->lei * $part, 2);

                    if (abs($lei) < 0.01) {
                        continue;
                    }

                    $rows[] = [
                        'invoice_id' => $invoice->id,
                        'supplier_name' => mb_substr((string) $buy->partener, 0, 200),
                        'supplier_tax_id' => $this->tax($buy->cod_cci ?? null),
                        'basis' => $basis,
                        'reference' => mb_substr((string) $line->{$field}, 0, 200),
                        'doc_ref' => $buy->docs > 1 ? $buy->docs.' documente' : mb_substr((string) $buy->doc_ref, 0, 160),
                        'doc_date' => $buy->docs > 1 ? null : $buy->doc_date,
                        'share' => $total > 0 ? round($lei / max($this->lei($invoice), 0.01), 6) : 0,
                        'val_lei' => $lei,
                        // Potrivirea e la document cât timp cheia e numai a
                        // acestei facturi; împărțită, rămâne o repartizare.
                        'exact' => abs($part - 1.0) < 0.0001,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Ce a cumpărat casa pe fiecare cheie.
     *
     * @param  Collection<int, string>  $keys
     * @return array<string, list<object>>
     */
    private function purchases(ConnectionInterface $db, string $field, Collection $keys, string $basis, array $window): array
    {
        $column = $field === 'com_int' ? 'p.com_int' : 'p.articol';
        $found = [];

        foreach ($keys->chunk(300) as $chunk) {
            $rows = $db->select(<<<SQL
                select nullif(trim({$column}), '') as key_value,
                       to_char(d.data_doc, 'YYYY-MM') as month,
                       d.partener, max(pa.cod_cci) as cod_cci,
                       count(distinct d.tip_doc || ' ' || d.nr_doc) as docs,
                       min(d.tip_doc || ' ' || d.nr_doc) as doc_ref,
                       min(d.data_doc) as doc_date,
                       sum(coalesce(p.cant, 0) * coalesce(p.pret, 0) * coalesce(d.curs, 1)) as lei
                from doc_poz p
                join doc d on d.data_doc = p.data_doc and d.tip_doc = p.tip_doc and d.nr_doc = p.nr_doc
                join tip_doc t on t.tip_doc = d.tip_doc
                left join partener pa on pa.partener = d.partener
                where t.grupa = 'furnizor'
                  and d.data_doc between ? and ?
                  and trim({$column}) in ({$this->marks($chunk)})
                  and replace(upper(coalesce(pa.cod_cci, '')), 'RO', '') <> ?
                group by 1, 2, d.partener
                having sum(coalesce(p.cant, 0) * coalesce(p.pret, 0) * coalesce(d.curs, 1)) <> 0
            SQL, [...$window, ...$chunk->values()->all(), self::OURS[0]]);

            foreach ($rows as $row) {
                $found[$this->key($basis, $row->key_value, $row->month)][] = $row;
            }
        }

        return $found;
    }

    /**
     * Pe ce răstimp se caută perechea.
     *
     * Articolul se potrivește în luna lui: o chirie de septembrie se
     * refacturează în septembrie. Rezervarea, nu — hotelul poate factura și
     * la două luni după ce s-a vândut sejurul.
     *
     * @return array{0: string, 1: string}
     */
    private function window(string $basis, Carbon $from, Carbon $to): array
    {
        return $basis === InvoiceSource::BASIS_ARTICLE
            ? [$from->toDateString(), $to->toDateString()]
            : [$from->copy()->subMonths(6)->toDateString(), $to->copy()->addMonths(6)->toDateString()];
    }

    /**
     * Cât s-a vândut cu totul pe fiecare cheie, ca împărțirea să fie dreaptă.
     *
     * @param  Collection<int, string>  $keys
     * @param  array{0: string, 1: string}  $window
     * @return array<string, float>
     */
    private function salesTotals(ConnectionInterface $db, string $us, string $field, Collection $keys, string $basis, array $window): array
    {
        $column = $field === 'com_int' ? 'p.com_int' : 'p.articol';
        $totals = [];

        foreach ($keys->chunk(300) as $chunk) {
            $rows = $db->select(<<<SQL
                select nullif(trim({$column}), '') as key_value,
                       to_char(d.data_doc, 'YYYY-MM') as month,
                       sum(coalesce(p.cant, 0) * coalesce(p.pret, 0) * coalesce(d.curs, 1)) as lei
                from doc_poz p
                join doc d on d.data_doc = p.data_doc and d.tip_doc = p.tip_doc and d.nr_doc = p.nr_doc
                join tip_doc t on t.tip_doc = d.tip_doc
                where d.partener = ? and t.grupa = 'client' and d.tip_doc <> 'FactINT'
                  and d.data_doc between ? and ?
                  and trim({$column}) in ({$this->marks($chunk)})
                group by 1, 2
            SQL, [$us, ...$window, ...$chunk->values()->all()]);

            foreach ($rows as $row) {
                $key = $this->key($basis, $row->key_value, $row->month);
                $totals[$key] = ($totals[$key] ?? 0) + (float) $row->lei;
            }
        }

        return $totals;
    }

    /** Rezervarea se potrivește oricând; articolul, numai în luna lui. */
    private function key(string $basis, ?string $value, ?string $month): string
    {
        return $basis === InvoiceSource::BASIS_ARTICLE
            ? $value.'@'.$month
            : (string) $value;
    }

    /**
     * Semnele de întrebare pentru un `in (...)`.
     *
     * @param  Collection<int, mixed>  $values
     */
    private function marks(Collection $values): string
    {
        return implode(',', array_fill(0, $values->count(), '?'));
    }

    /**
     * Valoarea facturii fără TVA, în lei.
     *
     * Liniile din baza casei sunt cantitate ori preț, adică fără taxă; dacă
     * le-am pune lângă totalul cu TVA, o refacturare curată ar arăta ca și
     * cum ar acoperi doar 83% din factură.
     */
    private function lei(Invoice $invoice): float
    {
        $net = (float) $invoice->val_mon - (float) $invoice->val_mon_tva;

        return round($net * (float) ($invoice->curs ?: 1), 2);
    }

    private function tax(?string $value): ?string
    {
        $tax = preg_replace('~[^A-Z0-9]~', '', strtoupper((string) $value));

        return $tax === '' ? null : mb_substr((string) $tax, 0, 40);
    }

    /**
     * @param  list<int>  $invoiceIds
     * @param  list<array<string, mixed>>  $rows
     */
    private function store(array $invoiceIds, array $rows): void
    {
        $now = now();

        DB::transaction(function () use ($invoiceIds, $rows, $now) {
            foreach (array_chunk($invoiceIds, 1000) as $chunk) {
                InvoiceSource::query()->whereIn('invoice_id', $chunk)->delete();
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                InvoiceSource::query()->insert(array_map(
                    fn (array $row) => [...$row, 'created_at' => $now, 'updated_at' => $now],
                    $chunk,
                ));
            }
        });
    }
}
