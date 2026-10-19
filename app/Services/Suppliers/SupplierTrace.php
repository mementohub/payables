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

    /** Câte legături se adună înainte de a fi scrise deodată. */
    private const BATCH = 500;

    /** Câte chei se cercetează într-un lot, ca să nu intre toată luna deodată. */
    private const KEYS_AT_A_TIME = 300;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    /** @var array<int, true> */
    private array $matched = [];

    private int $written = 0;

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

        // Legăturile vechi ies din capul locului, iar cele noi se scriu pe
        // măsură ce se găsesc. Înainte se strângea toată luna în memorie și
        // abia la urmă se salva — de acolo veneau gigabyte-ii.
        $this->clear($invoices->pluck('id')->all());
        $this->written = 0;
        $this->matched = [];
        $this->buffer = [];

        match ($house['basis']) {
            InvoiceSource::BASIS_ORDER => $this->byOrder($db, $invoices, $us, $from, $to),
            InvoiceSource::BASIS_BOOKING => $this->byKey($db, $invoices, $us, 'com_int', InvoiceSource::BASIS_BOOKING, $from, $to),
            InvoiceSource::BASIS_ARTICLE => $this->byKey($db, $invoices, $us, 'articol', InvoiceSource::BASIS_ARTICLE, $from, $to),
            default => null,
        };

        $this->flush();

        return [
            'invoices' => $invoices->count(),
            'matched' => count($this->matched),
            'sources' => $this->written,
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
    /**
     * Liniile facturilor pe care casa ni le-a emis, date una câte una.
     *
     * Se citesc în flux, nu se strâng într-o colecție: o lună de cazări are
     * zeci de mii de linii, iar ele n-au de ce să stea toate deodată în
     * memorie. Facturile de întocmit (`FactINT`) rămân afară: poartă același
     * număr ca cea adevărată și ar dubla sumele.
     *
     * @return \Generator<int, object>
     */
    private function salesLines(ConnectionInterface $db, string $us, Carbon $from, Carbon $to, string $field): \Generator
    {
        $column = $field === 'com_int' ? 'p.com_int' : 'p.articol';

        yield from $db->cursor(<<<SQL
            select trim(d.nr_doc) as nr_doc,
                   nullif(trim(coalesce({$column}, '')), '') as key_value,
                   to_char(d.data_doc, 'YYYY-MM') as month,
                   coalesce(p.cant, 0) * coalesce(p.pret, 0) * coalesce(d.curs, 1) as lei
            from doc_poz p
            join doc d on d.data_doc = p.data_doc and d.tip_doc = p.tip_doc and d.nr_doc = p.nr_doc
            join tip_doc t on t.tip_doc = d.tip_doc
            where d.partener = ? and t.grupa = 'client' and d.tip_doc <> 'FactINT'
              and d.data_doc between ? and ?
        SQL, [$us, $from->toDateString(), $to->toDateString()]);
    }

    /**
     * Memento Air: factura stă pe o comandă de zbor, comanda de zbor pe luna
     * de charter, iar pe lună stau facturile companiilor aeriene. Cum se
     * cumpără pe perioade și se vinde pe zboruri, furnizorii nu se pot pune
     * unul în dreptul altuia: se dau procentual, pe cât cântărește fiecare în
     * cheltuiala lunii.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    private function byOrder(ConnectionInterface $db, Collection $invoices, string $us, Carbon $from, Carbon $to): void
    {
        $byNumber = [];

        foreach ($invoices as $invoice) {
            $byNumber[trim((string) $invoice->nr_doc)] = $invoice;
        }

        // Liniile, strânse pe factură ca perechi comandă-sumă: tot ce trebuie
        // pentru cântărire, fără obiecte ținute degeaba.
        $weightsOf = [];
        $totals = [];
        $orders = [];

        foreach ($this->salesLines($db, $us, $from, $to, 'com_int') as $line) {
            $number = trim((string) $line->nr_doc);

            if (! isset($byNumber[$number]) || $line->key_value === null) {
                continue;
            }

            $weightsOf[$number][$line->key_value] = ($weightsOf[$number][$line->key_value] ?? 0.0) + (float) $line->lei;
            $totals[$number] = ($totals[$number] ?? 0.0) + (float) $line->lei;
            $orders[$line->key_value] = true;
        }

        if ($orders === []) {
            return;
        }

        $root = $this->roots($db, collect(array_keys($orders)));
        unset($orders);
        $mixes = [];

        foreach ($weightsOf as $number => $own) {
            $invoice = $byNumber[$number];
            $total = $totals[$number];

            if (abs($total) < 0.01) {
                continue;
            }

            // Cât cântărește fiecare lună de charter în factura asta.
            $weights = [];

            foreach ($own as $order => $lei) {
                $order = $root[$order] ?? $order;
                $weights[$order] = ($weights[$order] ?? 0) + $lei;
            }

            foreach ($weights as $order => $part) {
                $mixes[$order] ??= $this->orderMix($db, (string) $order);
                $mix = $mixes[$order];

                if ($mix['total'] <= 0) {
                    continue;
                }

                foreach ($mix['suppliers'] as $supplier) {
                    $share = max(-999.0, min(999.0, ($part / $total) * ($supplier->lei / $mix['total'])));

                    if (abs($share) < 0.0005) {
                        continue;
                    }

                    $this->write([
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
                    ]);
                }
            }
        }
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
     */
    private function byKey(ConnectionInterface $db, Collection $invoices, string $us, string $field, string $basis, Carbon $from, Carbon $to): void
    {
        $net = [];
        $byNumber = [];

        foreach ($invoices as $invoice) {
            $number = trim((string) $invoice->nr_doc);
            $byNumber[$number] = $invoice->id;
            $net[$invoice->id] = $this->lei($invoice);
        }

        // Liniile, strânse pe cheie și păstrate ca numere goale, nu ca
        // obiecte: dintr-o lună de cazări ies zeci de mii, iar un obiect
        // costă de câteva ori cât trei numere.
        $byKey = [];
        $bareOf = [];

        foreach ($this->salesLines($db, $us, $from, $to, $field) as $line) {
            $raw = $line->key_value;
            $id = $byNumber[trim((string) $line->nr_doc)] ?? null;

            if ($raw === null || $id === null) {
                continue;
            }

            $byKey[$raw][] = [$id, (float) $line->lei, (string) $line->month];
            $bareOf[$raw] ??= $this->bare($raw);
        }

        if ($byKey === []) {
            return;
        }

        // Cheile scrise altfel, dar aceleași, merg în același lot: altfel
        // numitorul s-ar socoti pe jumătate din ele.
        $groups = [];

        foreach ($bareOf as $raw => $bare) {
            $groups[$bare][] = $raw;
        }

        $window = $this->window($basis, $from, $to);

        foreach (array_chunk($groups, self::KEYS_AT_A_TIME, true) as $batch) {
            $keys = collect($batch)->flatten()->merge(array_keys($batch))->filter()->unique()->values();
            $buys = $this->purchases($db, $field, $keys, $basis, $window);
            $whole = $this->salesTotals($db, $us, $field, $keys, $basis, $window);

            foreach ($batch as $bare => $raws) {
                foreach ($raws as $raw) {
                    foreach ($byKey[$raw] as [$id, $lei, $month]) {
                        $this->emit($basis, $raw, (string) $bare, $month, $id, $lei, $net[$id] ?? 0.0, $buys, $whole);
                    }
                }
            }

            unset($buys, $whole, $keys);
        }
    }

    /**
     * O linie de vânzare, pusă în dreptul cheltuielii ei.
     *
     * Întâi cheia așa cum e scrisă: dacă pe ea s-a cumpărat, aia e. Abia dacă
     * nu găsește nimic se încearcă fără codița de la capăt — altfel am lipi
     * la un loc rezervări care chiar sunt deosebite.
     *
     * @param  array<string, array<string, list<object>>>  $buys
     * @param  array<string, array<string, float>>  $whole
     */
    private function emit(string $basis, string $raw, string $bare, string $month, int $invoiceId, float $lei, float $net, array $buys, array $whole): void
    {
        $key = $this->key($basis, $raw, $month);
        $found = $buys['raw'][$key] ?? null;
        $total = $whole['raw'][$key] ?? 0.0;

        if ($found === null) {
            $key = $this->key($basis, $bare, $month);
            $found = $buys['bare'][$key] ?? $buys['raw'][$key] ?? null;
            $total = $whole['bare'][$key] ?? 0.0;
        }

        if ($found === null || $total <= 0) {
            return;
        }

        // O linie nu poate lua mai mult decât toată cheltuiala cheii ei, nici
        // în plus, nici în minus.
        $part = max(-1.0, min(1.0, $lei / $total));

        foreach ($found as $buy) {
            $amount = round((float) $buy->lei * $part, 2);

            if (abs($amount) < 0.01) {
                continue;
            }

            $this->write([
                'invoice_id' => $invoiceId,
                'supplier_name' => mb_substr((string) $buy->partener, 0, 200),
                'supplier_tax_id' => $this->tax($buy->cod_cci ?? null),
                'basis' => $basis,
                'reference' => mb_substr($raw, 0, 200),
                'doc_ref' => $buy->docs > 1 ? $buy->docs.' documente' : mb_substr((string) $buy->doc_ref, 0, 160),
                'doc_date' => $buy->docs > 1 ? null : $buy->doc_date,
                'share' => $this->share($amount, $net),
                'val_lei' => $amount,
                // Potrivirea e la document cât timp cheia e numai a acestei
                // facturi; împărțită, rămâne o repartizare.
                'exact' => abs($part - 1.0) < 0.0001,
            ]);
        }
    }

    /**
     * Ce a cumpărat casa pe fiecare cheie.
     *
     * @param  Collection<int, string>  $keys
     * @return array<string, list<object>>
     */
    private function purchases(ConnectionInterface $db, string $field, Collection $keys, string $basis, array $window): array
    {
        $column = $this->column($field);
        $found = [];

        foreach ($keys->chunk(300) as $chunk) {
            $rows = $db->select(<<<SQL
                select nullif(trim(coalesce(p.{$field}, '')), '') as key_value,
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
                  and {$column} in ({$this->marks($chunk)})
                  and replace(upper(coalesce(pa.cod_cci, '')), 'RO', '') <> ?
                group by 1, 2, d.partener
                having sum(coalesce(p.cant, 0) * coalesce(p.pret, 0) * coalesce(d.curs, 1)) <> 0
            SQL, [...$window, ...$chunk->values()->all(), self::OURS[0]]);

            foreach ($rows as $row) {
                $key = $this->key($basis, $row->key_value, $row->month);
                $bare = $this->key($basis, $this->bare((string) $row->key_value), $row->month);

                $found['raw'][$key][] = $row;

                if ($bare !== $key) {
                    $found['bare'][$bare][] = $row;
                }
            }
        }

        return $found;
    }

    /** Cheia fără semnele de la coadă. */
    private function bare(string $value): string
    {
        return (string) preg_replace('~[^\p{L}\p{N}]+$~u', '', $value);
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
        $column = $this->column($field);
        $totals = [];

        foreach ($keys->chunk(300) as $chunk) {
            $rows = $db->select(<<<SQL
                select nullif(trim(coalesce(p.{$field}, '')), '') as key_value,
                       to_char(d.data_doc, 'YYYY-MM') as month,
                       sum(coalesce(p.cant, 0) * coalesce(p.pret, 0) * coalesce(d.curs, 1)) as lei
                from doc_poz p
                join doc d on d.data_doc = p.data_doc and d.tip_doc = p.tip_doc and d.nr_doc = p.nr_doc
                join tip_doc t on t.tip_doc = d.tip_doc
                where d.partener = ? and t.grupa = 'client' and d.tip_doc <> 'FactINT'
                  and d.data_doc between ? and ?
                  and {$column} in ({$this->marks($chunk)})
                group by 1, 2
            SQL, [$us, ...$window, ...$chunk->values()->all()]);

            foreach ($rows as $row) {
                $key = $this->key($basis, $row->key_value, $row->month);
                $bare = $this->key($basis, $this->bare((string) $row->key_value), $row->month);

                // Numai vânzările pozitive fac numitorul.
                //
                // O rezervare vândută și stornată are suma pe zero, iar
                // împărțind la zero se pierdea legătura cu totul — în iunie,
                // jumătate din facturi. Așa, vânzarea bună ia cheltuiala
                // întreagă, iar stornarea o ia cu minus: exact ce s-a
                // întâmplat.
                if ((float) $row->lei <= 0) {
                    continue;
                }

                $totals['raw'][$key] = ($totals['raw'][$key] ?? 0) + (float) $row->lei;
                // Pe raftul curățat se adună toate felurile în care s-a scris
                // aceeași rezervare: altfel numitorul ar fi mai mic decât
                // întregul și bucățile ar primi prea mult.
                $totals['bare'][$bare] = ($totals['bare'][$bare] ?? 0) + (float) $row->lei;
            }
        }

        return $totals;
    }

    /**
     * Coloana după care se caută, așa cum e scrisă.
     *
     * Curățarea codiței („1244079~” → „1244079”) nu se face aici, ci pe
     * amândouă fețele în PHP: se caută întâi cheia întreagă și abia pe urmă
     * cea curățată, ca să nu lipim la un loc rezervări care chiar sunt
     * deosebite.
     */
    private function column(string $field): string
    {
        return "trim(coalesce(p.{$field}, ''))";
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

    /**
     * Cât din factură îi revine furnizorului, ca parte din întreg.
     *
     * O factură poate fi pe zero — o stornare care se închide singură, o
     * corecție. Împărțind la ea, partea sărea la milioane și baza refuza
     * rândul; mai bine o parte nespusă decât una fără noimă. Suma în lei
     * rămâne oricum scrisă, și ea e ce contează.
     */
    private function share(float $lei, float $net): float
    {
        if (abs($net) < 1.0) {
            return 0.0;
        }

        return round(max(-999.0, min(999.0, $lei / $net)), 6);
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
    /**
     * Legăturile vechi ale facturilor cercetate, scoase înainte de a căuta
     * din nou.
     *
     * @param  list<int>  $invoiceIds
     */
    private function clear(array $invoiceIds): void
    {
        foreach (array_chunk($invoiceIds, 1000) as $chunk) {
            InvoiceSource::query()->whereIn('invoice_id', $chunk)->delete();
        }
    }

    /**
     * O legătură găsită. Se ține în mână doar până se strâng cinci sute, apoi
     * pleacă în baza de date și memoria se eliberează.
     *
     * @param  array<string, mixed>  $row
     */
    private function write(array $row): void
    {
        $this->buffer[] = $row;
        $this->matched[$row['invoice_id']] = true;

        if (count($this->buffer) >= self::BATCH) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $now = now();

        InvoiceSource::query()->insert(array_map(
            fn (array $row) => [...$row, 'created_at' => $now, 'updated_at' => $now],
            $this->buffer,
        ));

        $this->written += count($this->buffer);
        $this->buffer = [];
    }
}
