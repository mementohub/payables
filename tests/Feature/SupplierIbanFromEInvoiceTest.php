<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PartnerBankAccount;
use App\Models\PartnerEInvoiceAccount;
use App\Services\Approvals\InvoiceWorkflow;
use App\Services\EInvoices\EInvoiceXmlParser;
use App\Services\PaymentExport\BtPaymentRow;

function ublWithAccount(string $iban, ?string $provider = null): string
{
    $financial = $provider === null ? '' : '<cac:FinancialInstitutionBranch><cbc:ID>'.$provider.'</cbc:ID></cac:FinancialInstitutionBranch>';

    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
             xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
             xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
      <cbc:ID>FACT-1</cbc:ID>
      <cbc:IssueDate>2026-10-01</cbc:IssueDate>
      <cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>
      <cbc:DocumentCurrencyCode>RON</cbc:DocumentCurrencyCode>
      <cac:AccountingSupplierParty><cac:Party>
        <cac:PartyLegalEntity><cbc:RegistrationName>Furnizor SRL</cbc:RegistrationName></cac:PartyLegalEntity>
      </cac:Party></cac:AccountingSupplierParty>
      <cac:AccountingCustomerParty><cac:Party>
        <cac:PartyLegalEntity><cbc:RegistrationName>Christian Tour</cbc:RegistrationName></cac:PartyLegalEntity>
      </cac:Party></cac:AccountingCustomerParty>
      <cac:PaymentMeans>
        <cbc:PaymentMeansCode>42</cbc:PaymentMeansCode>
        <cac:PayeeFinancialAccount><cbc:ID>{$iban}</cbc:ID>{$financial}</cac:PayeeFinancialAccount>
      </cac:PaymentMeans>
      <cac:LegalMonetaryTotal>
        <cbc:PayableAmount currencyID="RON">100.00</cbc:PayableAmount>
      </cac:LegalMonetaryTotal>
    </Invoice>
    XML;
}

test('the payment account is read from the e-invoice, and only when it is a real IBAN', function () {
    $parser = app(EInvoiceXmlParser::class);

    expect($parser->extractPaymentAccounts(ublWithAccount('RO16BRDE450SV01028914500', 'BRDEROBU')))
        ->toBe([['iban' => 'RO16BRDE450SV01028914500', 'bic' => 'BRDEROBU', 'bank' => null]])
        // Spațiile nu contează, cifra de control da.
        ->and($parser->extractPaymentAccounts(ublWithAccount('RO16 BRDE 450S V010 2891 4500')))
        ->toBe([['iban' => 'RO16BRDE450SV01028914500', 'bic' => null, 'bank' => null]])
        // Unii scriu acolo numele băncii, alții un IBAN greșit de o cifră.
        ->and($parser->extractPaymentAccounts(ublWithAccount('BRD Hateg')))->toBe([])
        ->and($parser->extractPaymentAccounts(ublWithAccount('RO16BRDE450SV01028914501')))->toBe([])
        ->and($parser->extractPaymentAccounts(null))->toBe([]);
});

test('a supplier with no IBAN in OMC gets the one from its e-invoice, clearly marked', function () {
    $company = Company::factory()->create();
    $partner = Partner::query()->create(['company_id' => $company->id, 'name' => 'Furnizor SRL', 'cui' => 'RO123456', 'is_furnizor' => true]);

    PartnerEInvoiceAccount::query()->create([
        'partner_id' => $partner->id,
        'iban' => 'RO16BRDE450SV01028914500',
        'currency' => 'RON',
        'bic' => 'BRDEROBU',
        'nr_doc' => 'FACT-1',
        'seen_at' => now(),
    ]);

    $invoice = Invoice::factory()->for($company)->create([
        'partner_id' => $partner->id, 'moneda' => 'Lei', 'val_mon' => 100,
        'approval_status' => InvoiceWorkflow::APPROVED,
    ]);
    $invoice->load(['partner.bankAccounts', 'partner.eInvoiceAccounts']);

    $row = BtPaymentRow::build($invoice, 1);

    // „Lei” în OMC și „RON” în e-Factură sunt aceeași monedă.
    expect($row['target_account_number'])->toBe('RO16BRDE450SV01028914500')
        // BIC-ul de opt litere se duce la bancă umplut cu XXX, ca peste tot.
        ->and($row['beneficiary_bank_bic'])->toBe('BRDEROBUXXX')
        ->and($row['supplier_accounts'][0]['source'])->toBe('efactura')
        ->and($row['supplier_accounts'][0]['note'])->toBe('din e-Factura FACT-1')
        ->and($row['warnings'][0])->toContain('e-Factura furnizorului');
});

test('the account from OMC keeps the upper hand over the one read from an invoice', function () {
    $company = Company::factory()->create();
    $partner = Partner::query()->create(['company_id' => $company->id, 'name' => 'Furnizor SRL', 'cui' => 'RO123456', 'is_furnizor' => true]);

    PartnerBankAccount::query()->create([
        'partner_id' => $partner->id, 'iban' => 'RO71BTRLRONCRT0522375001', 'currency' => 'Lei', 'is_default' => false,
    ]);
    PartnerEInvoiceAccount::query()->create([
        'partner_id' => $partner->id, 'iban' => 'RO16BRDE450SV01028914500', 'currency' => 'RON', 'seen_at' => now(),
    ]);

    $invoice = Invoice::factory()->for($company)->create([
        'partner_id' => $partner->id, 'moneda' => 'Lei', 'val_mon' => 100,
    ]);
    $invoice->load(['partner.bankAccounts', 'partner.eInvoiceAccounts']);

    $row = BtPaymentRow::build($invoice, 1);

    // Un singur cont în ERP pe moneda facturii: el se ia, nu cel din factură.
    expect($row['target_account_number'])->toBe('RO71BTRLRONCRT0522375001')
        ->and($row['warnings'])->toBe([])
        ->and(collect($row['supplier_accounts'])->pluck('source')->all())->toBe(['omc', 'efactura']);
});

test('two accounts read from invoices are offered, not chosen', function () {
    $company = Company::factory()->create();
    $partner = Partner::query()->create(['company_id' => $company->id, 'name' => 'Furnizor SRL', 'cui' => 'RO123456', 'is_furnizor' => true]);

    foreach (['RO16BRDE450SV01028914500', 'RO71BTRLRONCRT0522375001'] as $iban) {
        PartnerEInvoiceAccount::query()->create(['partner_id' => $partner->id, 'iban' => $iban, 'currency' => 'RON', 'seen_at' => now()]);
    }

    $invoice = Invoice::factory()->for($company)->create(['partner_id' => $partner->id, 'moneda' => 'Lei', 'val_mon' => 100]);
    $invoice->load(['partner.bankAccounts', 'partner.eInvoiceAccounts']);

    $row = BtPaymentRow::build($invoice, 1);

    expect($row['target_account_number'])->toBeNull()
        ->and($row['warnings'][0])->toContain('mai multe conturi')
        ->and($row['supplier_accounts'])->toHaveCount(2);
});
