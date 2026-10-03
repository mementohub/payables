<?php

use App\Mail\CashFlowDailyMail;
use App\Models\CashFlowDetail;
use App\Models\CashFlowSnapshot;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Un raport ca cel construit noaptea: trei săptămâni, soldurile și cifrele
 * de care se agață mailul.
 */
function cashFlowPayload(): array
{
    return [
        'currency' => 'RON',
        'weeks' => ['2026-09-28', '2026-10-05', '2026-10-12'],
        'opening' => [
            'as_of' => '2026-10-01',
            'total' => 116654866.24,
            'by_currency' => ['RON' => 93024588.67, 'EUR' => 4471202.63, 'HUF' => 0.0],
        ],
        'lines' => [
            ['code' => 'A', 'kind' => 'balance', 'label' => 'Sold inițial', 'values' => [116654866.24, 102666896.13, 72762730.12]],
            ['code' => 'B7', 'kind' => 'value', 'label' => 'Încasări Altele', 'values' => [810727.0, 700000.0, 650000.0]],
            ['code' => 'B8', 'kind' => 'value', 'label' => 'Recuperare solduri restante ≤ 60 zile', 'values' => [4878966.0, 1200000.0, 900000.0]],
            ['code' => 'B11.1', 'kind' => 'value', 'label' => 'Vânzări noi – Pachete', 'values' => [2323266.0, 2400000.0, 2500000.0]],
            ['code' => 'B11', 'kind' => 'subtotal', 'label' => 'Încasări din vânzări noi – total', 'values' => [3032283.0, 3414133.31, 4562782.72]],
            ['code' => 'B12', 'kind' => 'value', 'label' => 'Încasări din facturi corporate (Tina)', 'values' => [2113948.0, 1900000.0, 1800000.0]],
            ['code' => 'B', 'kind' => 'total', 'label' => 'TOTAL ÎNCASĂRI', 'values' => [11843997.48, 10877382.26, 11144157.19]],
            ['code' => 'C1', 'kind' => 'value', 'label' => 'Plăți cazare (hoteluri)', 'values' => [2038207.0, 3000000.0, 1500000.0]],
            ['code' => 'C10', 'kind' => 'value', 'label' => 'Furnizori – sold neachitat la data raportului', 'values' => [16917497.0, 20000000.0, 7000000.0]],
            ['code' => 'C11', 'kind' => 'value', 'label' => 'Plăți furnizori pentru vânzări noi', 'values' => [1131567.0, 1200000.0, 1300000.0]],
            ['code' => 'C', 'kind' => 'total', 'label' => 'TOTAL PLĂȚI PRODUS', 'values' => [24198916.47, 38574360.79, 12018222.41]],
            ['code' => 'D8', 'kind' => 'value', 'label' => 'Consumabile, auto, întreținere', 'values' => [19729.0, 20000.0, 20000.0]],
            ['code' => 'D13', 'kind' => 'value', 'label' => 'Dividende', 'values' => [694241.0, 0.0, 0.0]],
            ['code' => 'D', 'kind' => 'total', 'label' => 'COSTURI CORPORATE', 'values' => [1633051.12, 2207187.48, 1633051.12]],
            ['code' => 'E1', 'kind' => 'total', 'label' => 'FLUX NET', 'values' => [-13987970.11, -29904166.01, -2507116.34]],
            ['code' => 'E2', 'kind' => 'balance', 'label' => 'SOLD FINAL', 'values' => [102666896.13, 72762730.12, 70255613.78]],
        ],
        'kpis' => [
            'in_13' => 119612852.57,
            'out_13' => 165137566.42,
            'closing_13' => 71130152.39,
            'min_closing' => ['week' => '2026-11-02', 'index' => 5, 'value' => 67242382.53],
        ],
    ];
}

beforeEach(function () {
    Mail::fake();
    config(['notifications.cash_flow.to' => 'stefan.petre@christiantour.ro']);
});

test('the report goes to Top Management, and to whoever else is written in the config', function () {
    CashFlowSnapshot::factory()->create(['payload' => cashFlowPayload()]);
    $boss = User::factory()->withRoles('top_management')->create(['email' => 'sef@christiantour.ro']);
    $alsoBoss = User::factory()->withRoles(['top_management', 'admin'])->create(['email' => 'sef2@christiantour.ro']);
    $clerk = User::factory()->withRoles('admin')->create(['email' => 'administrator@christiantour.ro']);
    config(['notifications.cash_flow.to' => 'trezorerie@christiantour.ro']);

    $this->artisan('cashflow:mail')->assertSuccessful();

    Mail::assertSent(CashFlowDailyMail::class, function (CashFlowDailyMail $mail) use ($boss, $alsoBoss, $clerk) {
        return $mail->hasTo($boss->email)
            && $mail->hasTo($alsoBoss->email)
            && $mail->hasTo('trezorerie@christiantour.ro')
            // Un cont de administrare nu e un om de decizie.
            && ! $mail->hasTo($clerk->email);
    });
});

test('the week block shows the report\'s own lines, with the pieces under them', function () {
    $snapshot = CashFlowSnapshot::factory()->create(['payload' => cashFlowPayload()]);

    $piece = fn (array $attributes) => CashFlowDetail::query()->create([
        'cash_flow_snapshot_id' => $snapshot->id,
        'week' => '2026-09-28',
        'actual' => false,
        'source' => 'test',
        ...$attributes,
    ]);

    $piece(['line' => 'B8', 'kind' => 'overdue', 'label' => 'Agenția Mare', 'reference' => 'D-1', 'date' => '2026-10-01', 'currency' => 'EUR', 'amount' => 80000, 'lei' => 400000]);
    $piece(['line' => 'B8', 'kind' => 'overdue', 'label' => 'Agenția Mică', 'reference' => 'D-2', 'lei' => 300000]);
    $piece(['line' => 'B8', 'kind' => 'overdue', 'label' => 'Agenția Mititică', 'reference' => 'D-3', 'lei' => 10000]);
    // Scenariul stă pe rândurile lui, iar ele se adună sub subtotal.
    $piece(['line' => 'B11.1', 'kind' => 'new_receipts', 'label' => 'Pachete – an anterior', 'reference' => '323 încasări', 'lei' => 500000]);
    // Banii care au trecut deja sunt scriși pe alte rânduri; coloana e prognoză.
    $piece(['line' => 'B8', 'kind' => 'overdue', 'label' => 'Încasat Deja', 'lei' => 900000, 'actual' => true]);
    // Altă săptămână, altă socoteală.
    $piece(['line' => 'B8', 'kind' => 'overdue', 'label' => 'Clientul de Luna Viitoare', 'week' => '2026-10-05', 'lei' => 800000]);

    $piece(['line' => 'C10', 'kind' => 'invoice', 'label' => 'Furnizor Greu', 'reference' => 'F-9', 'date' => '2026-10-02', 'lei' => 2000000]);
    $piece(['line' => 'D8', 'kind' => 'omc_payment', 'label' => 'Furnizor Ușor', 'lei' => 100000]);

    $digest = (new CashFlowDailyMail($snapshot))->digest;

    // Liniile, în ordinea mărimii lor din coloana săptămânii, nu a bucăților.
    expect(array_column($digest['top_in'], 'code'))->toBe(['B8', 'B11', 'B12'])
        ->and($digest['top_in'][0]['lei'])->toBe(4878966.0)
        ->and($digest['top_in'][0]['pieces'])->toBe(3)
        ->and(array_column($digest['top_in'][0]['documents'], 'partner'))->toBe(['Agenția Mare', 'Agenția Mică'])
        ->and($digest['top_in'][0]['documents'][0]['date'])->toBe('01.10.2026')
        ->and($digest['top_in'][0]['documents'][0]['currency'])->toBe('EUR')
        ->and($digest['top_in'][0]['rest'])->toBe(1)
        // Subtotalul scenariului își ia bucățile de pe rândurile lui.
        ->and($digest['top_in'][1]['documents'][0]['partner'])->toBe('Pachete – an anterior')
        ->and(array_column($digest['top_out'], 'code'))->toBe(['C10', 'C1', 'C11'])
        ->and($digest['top_out'][0]['documents'][0]['partner'])->toBe('Furnizor Greu')
        ->and($digest['current_week'])->toBe(['from' => '28.09', 'to' => '04.10.2026']);

    $html = (new CashFlowDailyMail($snapshot))->render();

    expect($html)->toContain('Top 3 linii de încasat')->toContain('Recuperare solduri restante')->toContain('Agenția Mare')
        ->toContain('Top 3 linii de plătit')->toContain('Furnizor Greu')
        ->not->toContain('Încasat Deja')
        ->not->toContain('Clientul de Luna Viitoare');
});

test('a top line never counts the same money twice', function () {
    $snapshot = CashFlowSnapshot::factory()->create(['payload' => cashFlowPayload()]);
    $digest = (new CashFlowDailyMail($snapshot))->digest;

    // Subtotalul scenariului intră, copiii lui nu: altfel topul ar depăși
    // totalul săptămânii din tabelul de dedesubt.
    expect(array_sum(array_column($digest['top_in'], 'lei')))->toBeLessThanOrEqual($digest['weeks'][0]['in'])
        ->and(array_sum(array_column($digest['top_out'], 'lei')))->toBeLessThanOrEqual($digest['weeks'][0]['out']);
});

test('on our own sales the client of the booking is us, so the piece says what it is', function () {
    $snapshot = CashFlowSnapshot::factory()->create(['payload' => cashFlowPayload()]);
    $company = Company::factory()->create(['name' => 'Christian Tour']);

    CashFlowDetail::query()->create([
        'cash_flow_snapshot_id' => $snapshot->id,
        'week' => '2026-09-28',
        'actual' => false,
        'source' => 'test',
        'line' => 'B8',
        'kind' => 'overdue',
        'label' => $company->name,
        'reference' => '10078421',
        'lei' => 25000,
    ]);

    expect((new CashFlowDailyMail($snapshot))->digest['top_in'][0]['documents'][0]['partner'])
        ->toBe('Clienți direcți (retail)');
});

test('a company of the group is not a client, and the mail says so', function () {
    $snapshot = CashFlowSnapshot::factory()->create(['payload' => cashFlowPayload()]);

    CashFlowDetail::query()->create([
        'cash_flow_snapshot_id' => $snapshot->id,
        'week' => '2026-09-28',
        'actual' => false,
        'source' => 'test',
        'line' => 'B8',
        'kind' => 'overdue',
        'label' => 'MEMENTO INTERNATIONAL SRL',
        'reference' => '1299736',
        'lei' => 336136,
    ]);

    $document = (new CashFlowDailyMail($snapshot))->digest['top_in'][0]['documents'][0];

    expect($document['partner'])->toBe('MEMENTO INTERNATIONAL SRL (intragrup)')
        // Banii rămân la locul lor: se scrie ce sunt, nu se ascund.
        ->and($document['lei'])->toBe(336136.0);
});

test('the mail is signed by Receivables & Payables', function () {
    expect(config('mail.from.name'))->toBe('Receivables & Payables');
});

test('without recorded pieces the lines still show, just without anything under them', function () {
    $snapshot = CashFlowSnapshot::factory()->create(['payload' => cashFlowPayload()]);

    $mail = new CashFlowDailyMail($snapshot);

    expect($mail->digest['top_in'][0]['code'])->toBe('B8')
        ->and($mail->digest['top_in'][0]['documents'])->toBe([])
        ->and($mail->render())->toContain('Top 3 linii de încasat');
});

test('a report with no lines at all leaves the week block out', function () {
    $snapshot = CashFlowSnapshot::factory()->create([
        'payload' => [...cashFlowPayload(), 'lines' => []],
    ]);

    $mail = new CashFlowDailyMail($snapshot);

    expect($mail->digest['top_in'])->toBe([])
        ->and($mail->digest['top_out'])->toBe([])
        ->and($mail->render())->not->toContain('Top 3');
});

test('the mail wears the Christian Tour colours and carries the logo inside it', function () {
    CashFlowSnapshot::factory()->create(['payload' => cashFlowPayload()]);

    $html = (new CashFlowDailyMail(CashFlowSnapshot::latestUsable()))->render();

    expect($html)->toContain('#011f5b')        // albastrul mărcii
        ->toContain('#ff4200')                 // portocaliul mărcii
        ->toContain('Nunito')
        ->toContain('Christian Tour')
        // Sigla e dusă cu mailul, nu luată de pe internet la deschidere.
        ->toContain('data:image/png;base64,');
});

test('the morning mail carries the position, the weeks ahead and the report itself', function () {
    $snapshot = CashFlowSnapshot::factory()->create([
        'built_at' => now()->subHours(4),
        'payload' => cashFlowPayload(),
        'sources' => [['key' => 'opening', 'label' => 'Sold inițial (OMC)', 'status' => 'ok']],
    ]);

    $this->artisan('cashflow:mail')->assertSuccessful();

    Mail::assertSent(CashFlowDailyMail::class, function (CashFlowDailyMail $mail) use ($snapshot) {
        $html = $mail->render();

        return $mail->hasTo('stefan.petre@christiantour.ro')
            && $mail->snapshot->is($snapshot)
            // Poziția de trezorerie, pe monedele în care chiar sunt bani.
            && $mail->digest['position'] === ['RON' => 93024588.67, 'EUR' => 4471202.63]
            // Plățile unei săptămâni sunt produsul plus corporate.
            && (int) round($mail->digest['weeks'][0]['out']) === 25831968
            && $mail->digest['weeks'][0]['closing'] === 102666896.13
            && str_contains($html, '93.024.588,67')
            && str_contains($html, 'Deschide raportul')
            && str_contains($mail->envelope()->subject, 'Trezorerie');
    });
});

test('the report comes attached as a spreadsheet', function () {
    CashFlowSnapshot::factory()->create(['payload' => cashFlowPayload()]);

    $mail = new CashFlowDailyMail(CashFlowSnapshot::latestUsable(), 8, 'xlsx');
    $attachments = $mail->attachments();

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->as)->toContain('WCFR-');
});

test('a report built from a broken sync says so in the mail', function () {
    CashFlowSnapshot::factory()->create([
        'payload' => cashFlowPayload(),
        'sources' => [
            ['key' => 'opening', 'label' => 'Sold inițial (OMC)', 'status' => 'error', 'error' => 'OMC nu răspunde'],
        ],
    ]);

    $mail = new CashFlowDailyMail(CashFlowSnapshot::latestUsable());

    expect($mail->digest['problems'])->toBe(['Sold inițial (OMC): OMC nu răspunde'])
        ->and($mail->render())->toContain('Raportul e incomplet.');
});

test('a failed build does not take the place of the last good report', function () {
    $good = CashFlowSnapshot::factory()->create([
        'built_at' => now()->subDay(),
        'payload' => cashFlowPayload(),
    ]);
    CashFlowSnapshot::factory()->create([
        'built_at' => now(),
        'status' => CashFlowSnapshot::STATUS_FAILED,
        'payload' => ['weeks' => [], 'lines' => [], 'kpis' => []],
        'error' => 'OMC nu răspunde',
    ]);

    $this->artisan('cashflow:mail')->assertSuccessful();

    Mail::assertSent(CashFlowDailyMail::class, fn (CashFlowDailyMail $mail) => $mail->snapshot->is($good));
});

test('with no report built, nothing is sent and the command says why', function () {
    $this->artisan('cashflow:mail')->assertFailed();

    Mail::assertNothingSent();
});

test('the config switch stops the morning mail', function () {
    CashFlowSnapshot::factory()->create(['payload' => cashFlowPayload()]);
    config(['notifications.cash_flow.enabled' => false]);

    $this->artisan('cashflow:mail')->assertSuccessful();

    Mail::assertNothingSent();
});

test('the recipient can be given on the command line', function () {
    CashFlowSnapshot::factory()->create(['payload' => cashFlowPayload()]);

    $this->artisan('cashflow:mail', ['--to' => ['cineva@christiantour.ro', 'nu-e-mail']])->assertSuccessful();

    Mail::assertSent(CashFlowDailyMail::class, fn (CashFlowDailyMail $mail) => $mail->hasTo('cineva@christiantour.ro'));
});
