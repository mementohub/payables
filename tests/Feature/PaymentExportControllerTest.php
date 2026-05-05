<?php

use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PartnerBankAccount;
use App\Models\User;

it('prepares only fully approved invoices and includes default supplier IBAN', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $partner = Partner::factory()->for($company)->create([
        'name' => 'Furnizor SRL',
        'cui' => 'RO12345678',
        'is_furnizor' => true,
    ]);

    PartnerBankAccount::create([
        'partner_id' => $partner->id,
        'bank' => 'BANCA TRANSILVANIA',
        'bic' => 'BTRL',
        'swift' => 'BTRLRO22',
        'iban' => 'RO20BTRL01301202111111XX',
        'currency' => 'RON',
        'is_default' => true,
        'is_discontinued' => false,
    ]);

    CompanyBankAccount::create([
        'company_id' => $company->id,
        'bank' => 'BANCA TRANSILVANIA',
        'iban' => 'RO99BTRL01301201111111XX',
        'currency' => 'RON',
        'bic' => 'BTRL',
        'swift' => 'BTRLRO22',
        'is_default' => true,
        'is_discontinued' => false,
    ]);

    $approved = Invoice::factory()->for($company)->for($partner)->create([
        'is_fully_approved' => true,
        'val_mon' => 1190,
        'val_mon_tva' => 190,
        'val_mon_paid' => 0,
        'moneda' => 'RON',
    ]);

    $unapproved = Invoice::factory()->for($company)->for($partner)->create([
        'is_fully_approved' => false,
    ]);

    $this->actingAs($user)
        ->postJson('/payments/bt/prepare', [
            'invoice_ids' => [$approved->id, $unapproved->id],
        ])
        ->assertOk()
        ->assertJsonPath('rows.0.invoice_id', $approved->id)
        ->assertJsonPath('rows.0.target_account_number', 'RO20BTRL01301202111111XX')
        ->assertJsonPath('rows.0.beneficiary_bank_bic', 'BTRLRO22XXX')
        ->assertJsonPath('rows.0.amount', 1190)
        ->assertJsonPath('invoices_skipped', 1)
        ->assertJsonCount(1, 'rows');
});

it('downloads BT xlsx with correct headers', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post('/payments/bt/download', [
            'source_account' => 'RO99BTRL01301201111111XX',
            'rows' => [
                [
                    'beneficiary_name' => 'Furnizor SRL',
                    'target_account_number' => 'RO20BTRL01301202111111XX',
                    'beneficiary_bank_bic' => 'BTRLRO22XXX',
                    'beneficiary_fiscal_code' => '12345678',
                    'amount' => 1190,
                    'payment_ref_1' => 'FactFI 12345',
                    'payment_ref_2' => '',
                    'value_date' => '2026-05-05',
                    'urgent' => 'F',
                ],
            ],
        ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->headers->get('Content-Disposition'))
        ->toContain('plata-bt-');
});
