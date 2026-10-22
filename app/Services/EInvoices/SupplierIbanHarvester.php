<?php

namespace App\Services\EInvoices;

use App\Models\CompanyBankAccount;
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
            $fromText = false;

            // Câmpul de plată lipsește la mulți; atunci se caută în text, dar
            // ce iese de acolo rămâne însemnat, fiindcă e o citire, nu o
            // declarație: conturile noastre, dacă apar în XML, se scot.
            if ($accounts === []) {
                $fromText = true;
                $accounts = array_map(
                    fn (string $iban) => ['iban' => $iban, 'bic' => null, 'bank' => null],
                    array_values(array_filter(
                        $this->parser->extractTextAccounts($xml),
                        fn (string $iban) => ! in_array($iban, $this->ours(), true),
                    )),
                );
            }

            if ($accounts === []) {
                continue;
            }

            foreach (array_slice($accounts, 0, 4) as $account) {
                $written += $this->remember($partner, $account, $eInvoice, $fromText);
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
     * IBAN-urile caselor noastre: dacă apar în XML, nu sunt ale furnizorului.
     *
     * @return list<string>
     */
    private function ours(): array
    {
        return $this->ours ??= CompanyBankAccount::query()
            ->pluck('iban')
            ->map(fn (string $iban) => strtoupper(preg_replace('/\s+/', '', $iban) ?? ''))
            ->all();
    }

    /** @var list<string>|null */
    private ?array $ours = null;

    /**
     * @param  array{iban: string, bic: ?string, bank: ?string}  $account
     */
    private function remember(Partner $partner, array $account, EInvoice $eInvoice, bool $fromText = false): int
    {
        $existing = PartnerEInvoiceAccount::query()
            ->where('partner_id', $partner->id)
            ->where('iban', $account['iban'])
            ->first();

        $values = [
            'currency' => $eInvoice->currency !== null ? strtoupper(trim((string) $eInvoice->currency)) : null,
            'bank' => $account['bank'],
            'bic' => $account['bic'],
            'from_text' => $fromText,
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
