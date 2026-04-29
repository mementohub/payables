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
     * Lightweight extraction for sync — only totals.
     *
     * @return array{total_amount: ?float, total_vat: ?float}
     */
    public function extractTotals(?string $xml): array
    {
        if ($xml === null || trim($xml) === '') {
            return ['total_amount' => null, 'total_vat' => null];
        }

        try {
            $totals = (new UblReader)->import($xml)->getTotals();
        } catch (\Throwable) {
            return ['total_amount' => null, 'total_vat' => null];
        }

        return [
            'total_amount' => $totals->payableAmount,
            'total_vat' => $totals->vatAmount,
        ];
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
            'address' => $party->getAddress(),
            'city' => $party->getCity(),
            'postal_code' => $party->getPostalCode(),
            'country' => $party->getCountry(),
            'contact_name' => $party->getContactName(),
            'contact_phone' => $party->getContactPhone(),
            'contact_email' => $party->getContactEmail(),
        ];
    }
}
