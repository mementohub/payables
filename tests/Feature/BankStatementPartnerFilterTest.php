<?php

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->treasury = User::factory()->create(['roles' => [User::ROLE_TREASURY]]);

    $this->statement = BankStatement::query()->create([
        'company_id' => $this->company->id,
        'data_extras' => '2026-09-20',
        'banca' => 'BT',
        'iban' => 'RO49AAAA1B31007593840000',
        'moneda' => 'RON',
        'lines_count' => 3,
        'unallocated_count' => 0,
        'total_incoming' => 1000,
        'total_outgoing' => 700,
        'total_unallocated' => 0,
    ]);

    $line = function (array $attributes) {
        return BankStatementLine::query()->create([
            'bank_statement_id' => $this->statement->id,
            'data_doc' => '2026-09-20',
            'tip_doc' => 'OP',
            'moneda' => 'RON',
            'val_allocated' => 0,
            ...$attributes,
        ]);
    };

    $partner = Partner::factory()->create(['company_id' => $this->company->id, 'name' => 'HOTEL PARADIS SRL', 'cui' => 'RO123']);

    $this->plata = $line(['nr_doc' => '1', 'direction' => 'outgoing', 'partner_id' => $partner->id, 'partener_name' => 'HOTEL PARADIS SRL', 'val_mon' => 700]);
    $this->incasare = $line(['nr_doc' => '2', 'direction' => 'incoming', 'partener_name' => null, 'cine_preda' => 'Agentia Ploiesti', 'val_mon' => 1000]);
    $this->altcineva = $line(['nr_doc' => '3', 'direction' => 'outgoing', 'partener_name' => 'ENEL ENERGIE SA', 'val_mon' => 120]);
});

/**
 * Trezoreria caută în extras după om, nu după coloană: numele apare când ca
 * partener recunoscut de OMC, când doar pe operațiune sau la cine predă banii.
 */
test('the statement can be filtered by who was paid or who paid', function () {
    $this->actingAs($this->treasury)->get("/bank-statements/{$this->statement->id}?partner=paradis")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('lines', 1)
            ->where('lines.0.id', $this->plata->id)
            ->where('filters.partner', 'paradis')
            ->where('shown.lines', 1)
            ->where('shown.outgoing', 700));

    // Numele scris doar la „cine predă” se găsește la fel. (Diacriticele nu
    // contează pe MySQL, unde colația e utf8mb4_unicode_ci; testul rulează pe
    // sqlite, deci datele lui le evită.)
    $this->actingAs($this->treasury)->get("/bank-statements/{$this->statement->id}?partner=ploiesti")
        ->assertInertia(fn ($page) => $page->has('lines', 1)->where('lines.0.id', $this->incasare->id));

    // Se poate căuta și după CUI, prin partenerul legat.
    $this->actingAs($this->treasury)->get("/bank-statements/{$this->statement->id}?partner=RO123")
        ->assertInertia(fn ($page) => $page->has('lines', 1)->where('lines.0.id', $this->plata->id));

    $this->actingAs($this->treasury)->get("/bank-statements/{$this->statement->id}")
        ->assertInertia(fn ($page) => $page->has('lines', 3));
});

test('the list of statements keeps only those that touch the partner', function () {
    $gol = BankStatement::query()->create([
        'company_id' => $this->company->id,
        'data_extras' => '2026-09-21',
        'iban' => 'RO49BBBB1B31007593840000',
        'moneda' => 'RON',
        'lines_count' => 0,
        'unallocated_count' => 0,
        'total_incoming' => 0,
        'total_outgoing' => 0,
        'total_unallocated' => 0,
    ]);

    $this->actingAs($this->treasury)->get('/bank-statements?partner=paradis')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('statements.data', 1)
            ->where('statements.data.0.id', $this->statement->id)
            ->where('filters.partner', 'paradis'));

    $this->actingAs($this->treasury)->get('/bank-statements')
        ->assertInertia(fn ($page) => $page->has('statements.data', 2));

    expect($gol->id)->not->toBeNull();
});

/**
 * Un furnizor e plătit din mai multe conturi, în luni diferite: întrebarea
 * „ce i-am plătit și din ce bancă” nu încape într-un singur extras.
 */
test('the transactions page finds every payment to a supplier, by bank', function () {
    $alt = BankStatement::query()->create([
        'company_id' => $this->company->id,
        'data_extras' => '2026-08-05',
        'banca' => 'ING',
        'iban' => 'RO49CCCC1B31007593840000',
        'moneda' => 'RON',
        'lines_count' => 1,
        'unallocated_count' => 0,
        'total_incoming' => 0,
        'total_outgoing' => 300,
        'total_unallocated' => 0,
    ]);

    BankStatementLine::query()->create([
        'bank_statement_id' => $alt->id,
        'data_doc' => '2026-08-05',
        'tip_doc' => 'OP',
        'nr_doc' => '9',
        'direction' => 'outgoing',
        'partener_name' => 'HOTEL PARADIS SRL',
        'moneda' => 'RON',
        'val_mon' => 300,
        'val_allocated' => 300,
    ]);

    $this->actingAs($this->treasury)->get('/bank-statements/transactions?partner=paradis&direction=outgoing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('bank-statements/transactions')
            ->has('lines.data', 2)
            // Sumarul se strânge pe bancă, nu pe fiecare combinație de cont,
            // monedă și sens: un furnizor mare apare pe zeci de IBAN-uri.
            // (Aliasul din interogare e `lines_count`: `lines` e cuvânt
            // rezervat în MySQL și pica pe server, deși sqlite îl accepta.)
            ->has('by_bank', 2)
            ->where('by_bank.0.banca', 'BT')
            ->where('by_bank.0.accounts', 1)
            ->where('by_bank.0.totals.0.total', 700)
            ->where('by_bank.1.banca', 'ING')
            ->where('by_bank.1.totals.0.total', 300)
            // Și totalul peste tot ce s-a filtrat.
            ->where('totals.lines', 2)
            ->where('totals.by_currency.0.total', 1000)
            // Conturile se desfac abia când e aleasă o bancă.
            ->has('by_account', 0));

    $this->actingAs($this->treasury)->get('/bank-statements/transactions?partner=paradis&banca=ING')
        ->assertInertia(fn ($page) => $page
            ->has('by_account', 1)
            ->where('by_account.0.iban', 'RO49CCCC1B31007593840000')
            ->has('lines.data', 1));

    // Contul se poate alege, iar atunci rămâne doar ce a plecat din el.
    $this->actingAs($this->treasury)->get('/bank-statements/transactions?partner=paradis&iban='.$alt->iban)
        ->assertInertia(fn ($page) => $page->has('lines.data', 1)->where('lines.data.0.statement.banca', 'ING'));
});
