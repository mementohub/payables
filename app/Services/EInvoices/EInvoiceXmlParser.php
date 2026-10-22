<?php

namespace App\Services\EInvoices;

use Einvoicing\Invoice as EInvoicingInvoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Readers\UblReader;

class EInvoiceXmlParser
{
    /**
     * @return array{parsed: ?array<string, mixed>, error: ?string}
     */
    public function parse(?string $xml): array
    {
        if ($xml === null || trim($xml) === '') {
            return ['parsed' => null, 'error' => 'XML indisponibil pentru această eFactură.'];
        }

        try {
            $invoice = (new UblReader)->import($xml);
        } catch (\Throwable $e) {
            return ['parsed' => null, 'error' => 'Nu am putut citi XML-ul: '.$e->getMessage()];
        }

        return ['parsed' => $this->transformInvoice($invoice), 'error' => null];
    }

    /**
     * Lightweight extraction for sync — totals plus document currency.
     *
     * @return array{total_amount: ?float, total_vat: ?float, currency: ?string}
     */
    public function extractTotals(?string $xml): array
    {
        if ($xml === null || trim($xml) === '') {
            return ['total_amount' => null, 'total_vat' => null, 'currency' => null];
        }

        try {
            $invoice = (new UblReader)->import($xml);
        } catch (\Throwable) {
            return ['total_amount' => null, 'total_vat' => null, 'currency' => null];
        }

        $totals = $invoice->getTotals();
        $currency = $invoice->getCurrency() ?? $totals->currency;

        return [
            'total_amount' => $totals->payableAmount,
            'total_vat' => $totals->vatAmount,
            'currency' => $currency !== null && trim($currency) !== '' ? strtoupper(trim($currency)) : null,
        ];
    }

    /**
     * Extract the seller's tax/company identifier from the XML.
     * Prefers the VAT number; falls back to the legal company id.
     */
    public function extractSellerTaxId(?string $xml): ?string
    {
        if ($xml === null || trim($xml) === '') {
            return null;
        }

        try {
            $seller = (new UblReader)->import($xml)->getSeller();
        } catch (\Throwable) {
            return null;
        }

        if ($seller === null) {
            return null;
        }

        $vat = $seller->getVatNumber();
        if ($vat !== null && trim($vat) !== '') {
            return $vat;
        }

        $companyId = $seller->getCompanyId()?->getValue();
        if ($companyId !== null && trim($companyId) !== '') {
            return $companyId;
        }

        return null;
    }

    /**
     * Conturile de plată scrise de furnizor în XML.
     *
     * Unii pun acolo numele băncii („BRD Hateg”) sau o literă rătăcită, deci
     * trece doar ce e IBAN adevărat: formă și cifră de control. Mai bine
     * niciun cont decât unul greșit, la care banii chiar pleacă.
     *
     * @return list<array{iban: string, bic: ?string, bank: ?string}>
     */
    public function extractPaymentAccounts(?string $xml): array
    {
        if ($xml === null || trim($xml) === '') {
            return [];
        }

        try {
            $payment = (new UblReader)->import($xml)->getPayment();
        } catch (\Throwable) {
            return [];
        }

        $accounts = [];

        foreach ($payment?->getTransfers() ?? [] as $transfer) {
            $iban = strtoupper(preg_replace('/\s+/', '', (string) $transfer->getAccountId()) ?? '');

            if (! self::isIban($iban) || isset($accounts[$iban])) {
                continue;
            }

            $provider = $transfer->getProvider() !== null ? trim((string) $transfer->getProvider()) : '';
            $name = $transfer->getAccountName() !== null ? trim((string) $transfer->getAccountName()) : '';

            $accounts[$iban] = [
                'iban' => $iban,
                // Furnizorii pun în „provider” fie BIC-ul, fie numele băncii.
                'bic' => preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $provider) === 1 ? $provider : null,
                'bank' => $provider !== '' && preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $provider) !== 1
                    ? $provider
                    : ($name !== '' ? $name : null),
            ];
        }

        return array_values($accounts);
    }

    /**
     * IBAN-urile scrise oriunde în text, pentru furnizorii care nu
     * completează câmpul de plată: mulți și-l pun la forma juridică —
     * „Cont IBAN RO77… Capital social…”.
     *
     * Nu se ghicește care e cel bun: se întorc toate, iar alegerea rămâne
     * a omului care plătește.
     *
     * @return list<string>
     */
    public function extractTextAccounts(?string $xml): array
    {
        if ($xml === null || trim($xml) === '') {
            return [];
        }

        $text = (string) preg_replace('/\s+/', ' ', strip_tags($xml));

        if (preg_match_all('/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/', strtoupper($text), $matches) !== 1 && $matches[0] === []) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $matches[0],
            fn (string $candidate) => self::isIban($candidate),
        )));
    }

    /**
     * IBAN după formă și după cifra de control (mod 97).
     */
    public static function isIban(string $value): bool
    {
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $value) !== 1 || strlen($value) < 15 || strlen($value) > 34) {
            return false;
        }

        $moved = substr($value, 4).substr($value, 0, 4);
        $digits = '';

        foreach (str_split($moved) as $char) {
            $digits .= ctype_digit($char) ? $char : (string) (ord($char) - 55);
        }

        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }

        return $remainder === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformInvoice(EInvoicingInvoice $invoice): array
    {
        $totals = $invoice->getTotals();

        return [
            'number' => $invoice->getNumber(),
            'issue_date' => $invoice->getIssueDate()?->format('Y-m-d'),
            'due_date' => $invoice->getDueDate()?->format('Y-m-d'),
            'tax_point_date' => $invoice->getTaxPointDate()?->format('Y-m-d'),
            'currency' => $invoice->getCurrency(),
            'notes' => $invoice->getNotes(),
            'buyer_reference' => $invoice->getBuyerReference(),
            'purchase_order_reference' => $invoice->getPurchaseOrderReference(),
            'contract_reference' => $invoice->getContractReference(),
            'paid_amount' => $invoice->getPaidAmount(),
            'rounding_amount' => $invoice->getRoundingAmount(),
            'seller' => $this->transformParty($invoice->getSeller()),
            'buyer' => $this->transformParty($invoice->getBuyer()),
            'payee' => $this->transformParty($invoice->getPayee()),
            'totals' => [
                'currency' => $totals->currency,
                'net_amount' => $totals->netAmount,
                'allowances_amount' => $totals->allowancesAmount,
                'charges_amount' => $totals->chargesAmount,
                'tax_exclusive_amount' => $totals->taxExclusiveAmount,
                'vat_amount' => $totals->vatAmount,
                'tax_inclusive_amount' => $totals->taxInclusiveAmount,
                'paid_amount' => $totals->paidAmount,
                'rounding_amount' => $totals->roundingAmount,
                'payable_amount' => $totals->payableAmount,
            ],
            'lines' => array_map(fn (InvoiceLine $line) => $this->transformLine($line), $invoice->getLines()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformLine(InvoiceLine $line): array
    {
        $netAmount = $line->getNetAmount();
        $vatRate = $line->getVatRate();
        $vatAmount = $netAmount !== null && $vatRate !== null
            ? round($netAmount * $vatRate / 100, 2)
            : null;

        return [
            'name' => $line->getName(),
            'description' => $line->getDescription(),
            'quantity' => $line->getQuantity(),
            'unit' => $line->getUnit(),
            'price' => $line->getPrice(),
            'net_amount' => $netAmount,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'vat_category' => $line->getVatCategory(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function transformParty(?Party $party): ?array
    {
        if ($party === null) {
            return null;
        }

        return [
            'name' => $party->getName(),
            'trading_name' => $party->getTradingName(),
            'vat_number' => $party->getVatNumber(),
            'company_id' => $party->getCompanyId()?->getValue(),
            'address' => array_values($party->getAddress()),
            'city' => $party->getCity(),
            'postal_code' => $party->getPostalCode(),
            'country' => $party->getCountry(),
            'contact_name' => $party->getContactName(),
            'contact_phone' => $party->getContactPhone(),
            'contact_email' => $party->getContactEmail(),
        ];
    }
}
