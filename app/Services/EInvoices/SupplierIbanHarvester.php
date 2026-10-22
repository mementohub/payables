<?php

namespace App\Services\EInvoices;

use App\Models\EInvoice;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PartnerEInvoiceAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Strânge conturile furnizorilor din e-Facturile lor.
 *
 * OMC nu le are pe toate: din furnizorii cu facturi în ultimul an, aproape
 * jumătate n-au niciun IBAN în ERP, iar trezoreria îl caută atunci prin
 * mailuri sau prin factura scanată. Dar furnizorul și-l scrie singur în
 * XML-ul trimis prin ANAF, în câmpul de plată al facturii — de acolo îl
 * luăm, îl verificăm (formă și cifră de control) și îl ținem deoparte, ca
 * propunere, nu ca evidență.
 */
class SupplierIbanHarvester
{
    /** Câte e-Facturi se încearcă pentru un furnizor până să renunțăm. */
    private const TRIES = 3;

    public function __construct(private readonly EInvoiceXmlParser $parser) {}

    /**
     * Furnizorii care au facturi în fereastra dată și niciun cont în OMC.
     *
     * @return Collection<int, Partner>
     */
    public function missing(int $months = 12): Collection
    {
        $ids = Invoice::query()
            ->where('partener_type', 'furnizor')
            ->whereNull('omc_removed_at')
            ->where('data_doc', '>=', Carbon::now()->subMonths($months)->toDateString())
            ->distinct()
            ->pluck('partner_id')
            ->filter()
            ->all();

        return Partner::query()
            ->whereIn('id', $ids)
            ->whereDoesntHave('bankAccounts', fn ($query) => $query->where('is_discontinued', false))
            ->orderBy('name')
            ->get(['id', 'name', 'cui']);
    }

    /**
     * Caută în e-Facturile furnizorului și scrie ce găsește.
     *
     * @return int câte conturi noi au intrat
     */
    public function forPartner(Partner $partner): int
    {
        $written = 0;

        foreach ($this->invoicesOf($partner) as $eInvoice) {
            try {
                $xml = $eInvoice->xml();
            } catch (Throwable) {
                continue;
            }

            $accounts = $this->parser->extractPaymentAccounts($xml);

            if ($accounts === []) {
                continue;
            }

            foreach ($accounts as $account) {
                $written += $this->remember($partner, $account, $eInvoice);
            }

            // Prima e-Factură cu cont bun e de ajuns: următoarele ar aduce
            // același IBAN, cu încă un drum la baza celorlalți.
            break;
        }

        return $written;
    }

    /**
     * @return Collection<int, EInvoice>
     */
    private function invoicesOf(Partner $partner): Collection
    {
        $cui = preg_replace('/\D/', '', (string) $partner->cui) ?? '';

        return EInvoice::query()
            ->when(
                $cui !== '',
                fn ($query) => $query->where('supplier_cui', 'like', '%'.$cui.'%'),
                fn ($query) => $query->where('partener_xml', 'like', '%'.$partner->name.'%'),
            )
            ->orderByDesc('msg_data_creare_d')
            ->limit(self::TRIES)
            ->get();
    }

    /**
     * @param  array{iban: string, bic: ?string, bank: ?string}  $account
     */
    private function remember(Partner $partner, array $account, EInvoice $eInvoice): int
    {
        $existing = PartnerEInvoiceAccount::query()
            ->where('partner_id', $partner->id)
            ->where('iban', $account['iban'])
            ->first();

        $values = [
            'currency' => $eInvoice->currency !== null ? strtoupper(trim((string) $eInvoice->currency)) : null,
            'bank' => $account['bank'],
            'bic' => $account['bic'],
            'e_invoice_id' => $eInvoice->id,
            'nr_doc' => $eInvoice->nr_doc_xml,
            'seen_at' => $eInvoice->msg_data_creare_d ?? Carbon::now(),
        ];

        if ($existing !== null) {
            $existing->update($values);

            return 0;
        }

        PartnerEInvoiceAccount::query()->create([
            'partner_id' => $partner->id,
            'iban' => $account['iban'],
            ...$values,
        ]);

        return 1;
    }
}
