<?php

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PartnerAccountChoice;
use App\Models\PartnerBankAccount;
use App\Models\PartnerEInvoiceAccount;
use App\Models\User;
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

test('an IBAN written in the body of the invoice is read too, but never picked on its own', function () {
    $parser = app(EInvoiceXmlParser::class);

    // Așa își scriu mulți contul: la forma juridică, lângă capitalul social.
    $xml = str_replace(
        '<cbc:RegistrationName>Furnizor SRL</cbc:RegistrationName>',
        '<cbc:RegistrationName>Furnizor SRL</cbc:RegistrationName>'
            .'<cbc:CompanyLegalForm>J40/1/2010 Banca X RON Cont IBAN RO16BRDE450SV01028914500 '
            .'EUR Cont IBAN RO71BTRLRONCRT0522375001 Capital social 200 RON</cbc:CompanyLegalForm>',
        ublWithAccount('RO16BRDE450SV01028914500'),
    );

    expect($parser->extractTextAccounts($xml))
        ->toBe(['RO16BRDE450SV01028914500', 'RO71BTRLRONCRT0522375001']);

    $company = Company::factory()->create();
    $partner = Partner::query()->create(['company_id' => $company->id, 'name' => 'Furnizor SRL', 'cui' => 'RO123456', 'is_furnizor' => true]);

    PartnerEInvoiceAccount::query()->create([
        'partner_id' => $partner->id, 'iban' => 'RO16BRDE450SV01028914500', 'currency' => 'RON',
        'from_text' => true, 'nr_doc' => 'FACT-1', 'seen_at' => now(),
    ]);

    $invoice = Invoice::factory()->for($company)->create(['partner_id' => $partner->id, 'moneda' => 'Lei', 'val_mon' => 100]);
    $invoice->load(['partner.bankAccounts', 'partner.eInvoiceAccounts']);

    $row = BtPaymentRow::build($invoice, 1);

    // Unul singur, dar cules din text: se oferă, nu se pune în rând.
    expect($row['target_account_number'])->toBeNull()
        ->and($row['supplier_accounts'][0]['note'])->toBe('din textul e-Facturii FACT-1')
        ->and($row['warnings'][0])->toContain('din textul e-Facturii');
});

test('the account used last time comes back as the proposal, when the ERP cannot choose', function () {
    $company = Company::factory()->create();
    $partner = Partner::query()->create(['company_id' => $company->id, 'name' => 'Furnizor SRL', 'cui' => 'RO123456', 'is_furnizor' => true]);

    // Patru conturi în lei, niciunul implicit: aplicația n-are cum să aleagă.
    foreach (['RO71BTRLRONCRT0522375001', 'RO16BRDE450SV01028914500'] as $iban) {
        PartnerBankAccount::query()->create(['partner_id' => $partner->id, 'iban' => $iban, 'currency' => 'Lei', 'is_default' => false]);
    }

    $invoice = Invoice::factory()->for($company)->create(['partner_id' => $partner->id, 'moneda' => 'Lei', 'val_mon' => 100]);
    $invoice->load(['partner.bankAccounts', 'partner.eInvoiceAccounts', 'partner.accountChoices']);

    expect(BtPaymentRow::build($invoice, 1)['target_account_number'])->toBeNull();

    // Omul a ales o dată; data viitoare i se propune aceeași alegere.
    PartnerAccountChoice::query()->create([
        'partner_id' => $partner->id, 'currency' => 'RON', 'iban' => 'RO16BRDE450SV01028914500',
        'last_used_at' => now()->subMonth(),
    ]);

    $invoice->load('partner.accountChoices');
    $row = BtPaymentRow::build($invoice, 1);

    expect($row['target_account_number'])->toBe('RO16BRDE450SV01028914500')
        ->and($row['warnings'][0])->toContain('folosit ultima dată');
});

test('an account the supplier no longer has is not proposed again', function () {
    $company = Company::factory()->create();
    $partner = Partner::query()->create(['company_id' => $company->id, 'name' => 'Furnizor SRL', 'cui' => 'RO123456', 'is_furnizor' => true]);

    foreach (['RO71BTRLRONCRT0522375001', 'RO16BRDE450SV01028914500'] as $iban) {
        PartnerBankAccount::query()->create(['partner_id' => $partner->id, 'iban' => $iban, 'currency' => 'Lei', 'is_default' => false]);
    }

    // Contul de anul trecut, scos între timp din ERP: nu se mai propune.
    PartnerAccountChoice::query()->create([
        'partner_id' => $partner->id, 'currency' => 'RON', 'iban' => 'RO62BFER248000025828RO01', 'last_used_at' => now()->subYear(),
    ]);

    $invoice = Invoice::factory()->for($company)->create(['partner_id' => $partner->id, 'moneda' => 'Lei', 'val_mon' => 100]);
    $invoice->load(['partner.bankAccounts', 'partner.eInvoiceAccounts', 'partner.accountChoices']);

    expect(BtPaymentRow::build($invoice, 1)['target_account_number'])->toBeNull();
});

test('downloading the bank file remembers which account was paid', function () {
    $company = Company::factory()->create();
    $partner = Partner::query()->create(['company_id' => $company->id, 'name' => 'Furnizor SRL', 'cui' => 'RO123456', 'is_furnizor' => true]);
    $invoice = Invoice::factory()->for($company)->create(['partner_id' => $partner->id, 'moneda' => 'Lei', 'val_mon' => 100]);

    $user = User::factory()->withRoles('treasury')->create();

    $post = fn (string $iban) => $this->actingAs($user)->post('/payments/bt/download', [
        'source_account' => 'RO86BTRL04101202I04993XX',
        'rows' => [[
            'invoice_id' => $invoice->id,
            'beneficiary_name' => 'Furnizor SRL',
            'target_account_number' => $iban,
            'beneficiary_bank_bic' => 'BRDEROBUXXX',
            'amount' => 100,
            'payment_ref_1' => 'F1',
            'payment_ref_2' => '',
            'value_date' => now()->toDateString(),
            'urgent' => 'F',
        ]],
    ]);

    $post('RO16BRDE450SV01028914500')->assertOk();

    $choice = PartnerAccountChoice::query()->where('partner_id', $partner->id)->sole();
    expect($choice->iban)->toBe('RO16BRDE450SV01028914500')
        ->and($choice->currency)->toBe('RON')
        ->and($choice->times_used)->toBe(1)
        ->and($choice->last_used_by_id)->toBe($user->id);

    // Aceeași alegere a doua oară: se numără, nu se dublează.
    $post('RO16BRDE450SV01028914500')->assertOk();
    expect($choice->fresh()->times_used)->toBe(2);

    // Alt cont: ăsta devine cel ținut minte, iar numărătoarea o ia de la capăt.
    $post('RO71BTRLRONCRT0522375001')->assertOk();
    $choice = $choice->fresh();
    expect($choice->iban)->toBe('RO71BTRLRONCRT0522375001')->and($choice->times_used)->toBe(1);
});

test('the memory starts from the payments that actually went out', function () {
    $company = Company::factory()->create();
    $partner = Partner::query()->create(['company_id' => $company->id, 'name' => 'Furnizor SRL', 'cui' => 'RO123456', 'is_furnizor' => true]);

    $statement = BankStatement::query()->create([
        'company_id' => $company->id, 'data_extras' => now()->subMonth()->toDateString(),
        'banca' => 'BT', 'iban' => 'RO86BTRL04101202I04993XX', 'moneda' => 'Lei',
    ]);

    $line = fn (string $text, string $when) => BankStatementLine::query()->create([
        'bank_statement_id' => $statement->id, 'partner_id' => $partner->id, 'direction' => 'outgoing',
        'data_doc' => $when, 'tip_doc' => 'OP', 'nr_doc' => '1', 'moneda' => 'Lei', 'val_mon' => 100, 'obs_txt' => $text,
    ]);

    $line('Furnizor SRL, CONT RO71BTRLRONCRT0522375001, OP: 1', now()->subMonths(3)->toDateString());
    $line('Furnizor SRL, CONT RO16BRDE450SV01028914500, OP: 2', now()->subMonth()->toDateString());
    // Un rând fără cont scris nu strică nimic.
    $line('Furnizor SRL, plata OP', now()->subDays(3)->toDateString());

    $this->artisan('suppliers:seed-account-choices')->assertSuccessful();

    $choice = PartnerAccountChoice::query()->where('partner_id', $partner->id)->sole();

    // Ultima plată câștigă, iar sursa se vede.
    expect($choice->iban)->toBe('RO16BRDE450SV01028914500')
        ->and($choice->source)->toBe('bank')
        ->and($choice->currency)->toBe('RON');

    $invoice = Invoice::factory()->for($company)->create(['partner_id' => $partner->id, 'moneda' => 'Lei', 'val_mon' => 100]);
    $invoice->load(['partner.bankAccounts', 'partner.eInvoiceAccounts', 'partner.accountChoices']);

    $row = BtPaymentRow::build($invoice, 1);

    expect($row['target_account_number'])->toBe('RO16BRDE450SV01028914500')
        ->and($row['warnings'][0])->toContain('din extrasul bancar');
});
