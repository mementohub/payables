<?php

namespace App\Services\PaymentExport;

use App\Models\Invoice;
use App\Models\PartnerBankAccount;
use App\Models\PartnerEInvoiceAccount;

class BtPaymentRow
{
    /**
     * Build a precomputed row payload for an invoice — frontend can edit any field.
     *
     * @return array{
     *     invoice_id: int,
     *     order_number: int,
     *     beneficiary_name: string,
     *     beneficiary_fiscal_code: string,
     *     target_account_number: ?string,
     *     beneficiary_bank_bic: ?string,
     *     amount: float,
     *     currency: ?string,
     *     payment_ref_1: string,
     *     payment_ref_2: string,
     *     value_date: string,
     *     urgent: string,
     *     supplier_accounts: list<array{iban: string, currency: ?string, bank: ?string, bic: ?string, swift: ?string, is_default: bool, source: string, note: ?string}>,
     *     warnings: list<string>
     * }
     */
    public static function build(Invoice $invoice, int $orderNumber): array
    {
        $partner = $invoice->partner;
        $accounts = self::accountsFor($partner?->bankAccounts->all() ?? [], $invoice->moneda);
        $picked = self::pickAccount($accounts, $invoice->moneda);

        // Ce n-are OMC, poate avea chiar factura: furnizorul își scrie contul
        // în XML-ul trimis prin ANAF. E o propunere, nu evidență, așa că se
        // vede de unde vine și se ia numai dacă ERP-ul n-are nimic pe moneda
        // aia — altfel contul oficial ar fi dat la o parte de unul citit
        // dintr-o factură veche.
        $fromEInvoice = self::eInvoiceAccounts($partner?->eInvoiceAccounts->all() ?? [], $accounts, $invoice->moneda);
        $borrowed = null;

        if ($picked === null && count($fromEInvoice) === 1) {
            $borrowed = $fromEInvoice[0];
        }

        $rest = $invoice->outstandingAmount();

        $warnings = [];
        if ($picked === null && $borrowed !== null) {
            $warnings[] = 'IBAN luat din e-Factura furnizorului'
                .($borrowed->nr_doc ? ' (factura '.$borrowed->nr_doc.')' : '')
                .' — OMC nu are niciunul. Verifică-l înainte de plată.';
        } elseif ($picked === null) {
            $warnings[] = $fromEInvoice === []
                ? 'Furnizorul nu are IBAN configurat pentru moneda facturii.'
                : 'Furnizorul are mai multe conturi în e-Facturi: alege unul.';
        }
        if ($rest <= 0) {
            $warnings[] = 'Factura nu are sold de plată.';
        }

        return [
            'invoice_id' => $invoice->id,
            'order_number' => $orderNumber,
            'beneficiary_name' => $partner?->name ?? '',
            'beneficiary_fiscal_code' => self::normalizeCui($partner?->cui ?? ''),
            'target_account_number' => $picked?->iban ?? $borrowed?->iban,
            'beneficiary_bank_bic' => $picked !== null
                ? self::formatBic($picked->swift, $picked->bic)
                : self::formatBic(null, $borrowed?->bic),
            'amount' => $rest,
            'currency' => $invoice->moneda,
            'payment_ref_1' => (string) ($invoice->nr_doc ?? ''),
            'payment_ref_2' => '',
            'value_date' => now()->toDateString(),
            'urgent' => 'F',
            'supplier_accounts' => [
                ...array_map(
                    fn (PartnerBankAccount $account) => [
                        'iban' => $account->iban,
                        'currency' => $account->currency,
                        'bank' => $account->bank,
                        'bic' => $account->bic,
                        'swift' => $account->swift,
                        'is_default' => (bool) $account->is_default,
                        'source' => 'omc',
                        'note' => null,
                    ],
                    $accounts
                ),
                ...array_map(
                    fn (PartnerEInvoiceAccount $account) => [
                        'iban' => $account->iban,
                        'currency' => $account->currency,
                        'bank' => $account->bank,
                        'bic' => $account->bic,
                        'swift' => null,
                        'is_default' => false,
                        'source' => 'efactura',
                        'note' => $account->nr_doc !== null
                            ? 'din e-Factura '.$account->nr_doc
                            : 'din e-Factură',
                    ],
                    $fromEInvoice
                ),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Conturile din e-Facturi care merită arătate: cele care nu sunt deja în
     * OMC și care se potrivesc la monedă. Un cont fără monedă scrisă se
     * socotește bun pentru moneda facturii — XML-ul nu are câmp de monedă pe
     * cont, iar moneda e a facturii din care l-am citit.
     *
     * @param  list<PartnerEInvoiceAccount>  $accounts
     * @param  list<PartnerBankAccount>  $known
     * @return list<PartnerEInvoiceAccount>
     */
    private static function eInvoiceAccounts(array $accounts, array $known, ?string $currency): array
    {
        $alreadyKnown = array_map(fn (PartnerBankAccount $account) => strtoupper($account->iban), $known);

        return array_values(array_filter(
            $accounts,
            fn (PartnerEInvoiceAccount $account) => ! in_array(strtoupper($account->iban), $alreadyKnown, true)
                && ($account->currency === null || self::sameCurrency($account->currency, $currency)),
        ));
    }

    /** „Lei” din OMC și „RON” din e-Factură sunt aceeași monedă. */
    private static function sameCurrency(?string $one, ?string $other): bool
    {
        $normalise = fn (?string $value) => match (strtoupper(trim((string) $value))) {
            'LEI', 'RON' => 'RON',
            default => strtoupper(trim((string) $value)),
        };

        return $normalise($one) === $normalise($other);
    }

    /**
     * @param  list<PartnerBankAccount>  $accounts
     * @return list<PartnerBankAccount>
     */
    private static function accountsFor(array $accounts, ?string $currency): array
    {
        $filtered = array_values(array_filter(
            $accounts,
            fn (PartnerBankAccount $account) => ! $account->is_discontinued
        ));

        usort($filtered, function (PartnerBankAccount $a, PartnerBankAccount $b) use ($currency) {
            $aMatchesCurrency = $a->currency === $currency ? 0 : 1;
            $bMatchesCurrency = $b->currency === $currency ? 0 : 1;

            if ($aMatchesCurrency !== $bMatchesCurrency) {
                return $aMatchesCurrency <=> $bMatchesCurrency;
            }

            return ((int) $b->is_default) <=> ((int) $a->is_default);
        });

        return $filtered;
    }

    /**
     * @param  list<PartnerBankAccount>  $accounts
     */
    private static function pickAccount(array $accounts, ?string $currency): ?PartnerBankAccount
    {
        $matching = array_values(array_filter(
            $accounts,
            fn (PartnerBankAccount $account) => $account->currency === $currency
        ));

        if (empty($matching)) {
            return null;
        }

        foreach ($matching as $account) {
            if ($account->is_default) {
                return $account;
            }
        }

        return count($matching) === 1 ? $matching[0] : null;
    }

    private static function formatBic(?string $swift, ?string $bicShort): ?string
    {
        $swift = $swift !== null ? trim($swift) : '';
        $bicShort = $bicShort !== null ? trim($bicShort) : '';

        if ($swift === '' && $bicShort === '') {
            return null;
        }

        $base = $swift !== '' ? $swift : $bicShort;

        return strlen($base) === 8 ? $base.'XXX' : $base;
    }

    private static function normalizeCui(string $cui): string
    {
        $upper = strtoupper(trim($cui));

        return str_starts_with($upper, 'RO') ? substr($upper, 2) : $upper;
    }
}
