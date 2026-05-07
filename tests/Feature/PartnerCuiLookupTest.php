<?php

use App\Models\Company;
use App\Models\EInvoice;
use App\Models\Partner;
use App\Services\EInvoices\PartnerCuiLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('extracts cif_emitent from msg_detalii', function () {
    expect(EInvoice::extractEmitentCui('Factura cu id_incarcare=6172658067 emisa de cif_emitent=41602569 pentru cif_beneficiar=31370020'))
        ->toBe('41602569');
    expect(EInvoice::extractEmitentCui(null))->toBeNull();
    expect(EInvoice::extractEmitentCui(''))->toBeNull();
    expect(EInvoice::extractEmitentCui('something else'))->toBeNull();
});

it('normalizes CUIs by stripping RO prefix, whitespace and uppercasing', function () {
    expect(PartnerCuiLookup::normalize('RO1243237'))->toBe('1243237');
    expect(PartnerCuiLookup::normalize('ro1243237'))->toBe('1243237');
    expect(PartnerCuiLookup::normalize('  RO 1243237  '))->toBe('1243237');
    expect(PartnerCuiLookup::normalize('1243237'))->toBe('1243237');
});

it('strips the SeniorERP trailing-letter suffix when computing the base key', function () {
    expect(PartnerCuiLookup::normalizeBase('RO1243237a'))->toBe('1243237');
    expect(PartnerCuiLookup::normalizeBase('B62880992b'))->toBe('B62880992');
    expect(PartnerCuiLookup::normalizeBase('RO 1559737a'))->toBe('1559737');
    expect(PartnerCuiLookup::normalizeBase('RO1243237'))->toBe('1243237');
});

it('matches a partner whose CUI has the SeniorERP trailing-letter suffix', function () {
    $company = Company::factory()->create();
    $partner = Partner::factory()->for($company)->create(['cui' => 'RO1243237a']);

    $lookup = new PartnerCuiLookup($company->id);

    expect($lookup->find('RO1243237'))->toBe($partner->id);
    expect($lookup->find('1243237'))->toBe($partner->id);
});

it('prefers the alphabetically-first suffix when multiple partners share the same base CUI', function () {
    $company = Company::factory()->create();
    $partnerB = Partner::factory()->for($company)->create(['cui' => 'B62880992b']);
    $partnerA = Partner::factory()->for($company)->create(['cui' => 'B62880992a']);

    $lookup = new PartnerCuiLookup($company->id);

    expect($lookup->find('B62880992a'))->toBe($partnerA->id);
    expect($lookup->find('B62880992b'))->toBe($partnerB->id);
    expect($lookup->find('B62880992'))->toBe($partnerA->id);
});

it('returns null for empty or unmatched CUIs', function () {
    $company = Company::factory()->create();
    Partner::factory()->for($company)->create(['cui' => 'RO1243237a']);

    $lookup = new PartnerCuiLookup($company->id);

    expect($lookup->find(null))->toBeNull();
    expect($lookup->find(''))->toBeNull();
    expect($lookup->find('  '))->toBeNull();
    expect($lookup->find('RO9999999'))->toBeNull();
});

it('does not leak partners across companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    Partner::factory()->for($companyA)->create(['cui' => 'RO1243237a']);

    $lookup = new PartnerCuiLookup($companyB->id);

    expect($lookup->find('RO1243237'))->toBeNull();
});
