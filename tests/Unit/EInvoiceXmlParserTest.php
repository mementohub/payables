<?php

use App\Services\EInvoices\EInvoiceXmlParser;

it('extracts seller VAT number from UBL XML', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
         xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
         xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>F123</cbc:ID>
  <cbc:IssueDate>2026-02-09</cbc:IssueDate>
  <cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>
  <cbc:DocumentCurrencyCode>RON</cbc:DocumentCurrencyCode>
  <cac:AccountingSupplierParty>
    <cac:Party>
      <cac:PartyName><cbc:Name>RTC PROFFICE EXPERIENCE S.R.L.</cbc:Name></cac:PartyName>
      <cac:PartyTaxScheme>
        <cbc:CompanyID>RO6562512</cbc:CompanyID>
        <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
      </cac:PartyTaxScheme>
      <cac:PartyLegalEntity>
        <cbc:RegistrationName>RTC PROFFICE EXPERIENCE S.R.L.</cbc:RegistrationName>
        <cbc:CompanyID>J23/5375/22.08.2023</cbc:CompanyID>
      </cac:PartyLegalEntity>
    </cac:Party>
  </cac:AccountingSupplierParty>
  <cac:AccountingCustomerParty>
    <cac:Party>
      <cac:PartyLegalEntity><cbc:RegistrationName>Buyer</cbc:RegistrationName></cac:PartyLegalEntity>
    </cac:Party>
  </cac:AccountingCustomerParty>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="RON">100.00</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
XML;

    expect((new EInvoiceXmlParser)->extractSellerTaxId($xml))->toBe('RO6562512');
});

it('returns null for empty xml', function () {
    expect((new EInvoiceXmlParser)->extractSellerTaxId(null))->toBeNull();
    expect((new EInvoiceXmlParser)->extractSellerTaxId(''))->toBeNull();
});
