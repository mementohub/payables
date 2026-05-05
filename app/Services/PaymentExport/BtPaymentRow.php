<?php

namespace App\Services\PaymentExport;

use App\Models\Invoice;
use App\Models\PartnerBankAccount;

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
     *     supplier_accounts: list<array{iban: string, currency: string, bank: ?string, bic: ?string, swift: ?string, is_default: bool}>,
     *     warnings: list<string>
     * }
     */
    public static function build(Invoice $invoice, int $orderNumber): array
    {
        $partner = $invoice->partner;
        $accounts = self::accountsFor($partner?->bankAccounts->all() ?? [], $invoice->moneda);
        $picked = self::pickAccount($accounts, $invoice->moneda);

        $rest = round((float) $invoice->val_mon - (float) $invoice->val_mon_paid, 2);

        $warnings = [];
        if ($picked === null) {
            $warnings[] = 'Furnizorul nu are IBAN configurat pentru moneda facturii.';
        }
        if ($rest <= 0) {
            $warnings[] = 'Factura nu are sold de plată.';
        }

        return [
            'invoice_id' => $invoice->id,
            'order_number' => $orderNumber,
            'beneficiary_name' => $partner?->name ?? '',
            'beneficiary_fiscal_code' => self::normalizeCui($partner?->cui ?? ''),
            'target_account_number' => $picked?->iban,
            'beneficiary_bank_bic' => self::formatBic($picked?->swift, $picked?->bic),
            'amount' => $rest,
            'currency' => $invoice->moneda,
            'payment_ref_1' => (string) ($invoice->nr_doc ?? ''),
            'payment_ref_2' => '',
            'value_date' => now()->toDateString(),
            'urgent' => 'F',
            'supplier_accounts' => array_map(
                fn (PartnerBankAccount $account) => [
                    'iban' => $account->iban,
                    'currency' => $account->currency,
                    'bank' => $account->bank,
                    'bic' => $account->bic,
                    'swift' => $account->swift,
                    'is_default' => (bool) $account->is_default,
                ],
                $accounts
            ),
            'warnings' => $warnings,
        ];
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
