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
