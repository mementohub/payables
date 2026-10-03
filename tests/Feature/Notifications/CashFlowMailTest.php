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
            ['code' => 'B', 'kind' => 'total', 'label' => 'TOTAL ÎNCASĂRI', 'values' => [11843997.48, 10877382.26, 11144157.19]],
            ['code' => 'C', 'kind' => 'total', 'label' => 'TOTAL PLĂȚI PRODUS', 'values' => [24198916.47, 38574360.79, 12018222.41]],
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

test('the mail names who the current week hangs on, on both sides', function () {
    $snapshot = CashFlowSnapshot::factory()->create(['payload' => cashFlowPayload()]);

    $piece = fn (array $attributes) => CashFlowDetail::query()->create([
        'cash_flow_snapshot_id' => $snapshot->id,
        'week' => '2026-09-28',
        'actual' => false,
        'source' => 'test',
        ...$attributes,
    ]);

    // Un client cu două hârtii bate unul cu una singură, mai mare.
    $piece(['line' => 'B7', 'kind' => 'tranche', 'label' => 'Agenția Mare', 'reference' => 'D-1', 'date' => '2026-10-01', 'currency' => 'EUR', 'amount' => 80000, 'lei' => 400000]);
    $piece(['line' => 'B7', 'kind' => 'tranche', 'label' => 'Agenția Mare', 'reference' => 'D-2', 'lei' => 300000]);
    $piece(['line' => 'B7', 'kind' => 'tranche', 'label' => 'Agenția Mare', 'reference' => 'D-3', 'lei' => 10000]);
    $piece(['line' => 'B8', 'kind' => 'overdue', 'label' => 'Agenția Mică', 'reference' => 'D-4', 'lei' => 500000]);
    // Scenariul și restul neexplicat n-au contraparte: nu intră în top.
    $piece(['line' => 'B11', 'kind' => 'new_receipts', 'label' => 'Săptămâna 29.09', 'lei' => 9000000]);
    $piece(['line' => 'BX', 'kind' => 'residual', 'label' => 'Total OMC', 'lei' => 8000000]);

    $piece(['line' => 'C10', 'kind' => 'invoice', 'label' => 'Furnizor Greu', 'reference' => 'F-9', 'date' => '2026-10-02', 'lei' => 2000000]);
    $piece(['line' => 'D8', 'kind' => 'omc_payment', 'label' => 'Furnizor Ușor', 'lei' => 100000]);
    $piece(['line' => 'C10', 'kind' => 'invoice', 'label' => 'Fără partener', 'lei' => 5000000]);
    // Banii care au trecut deja sunt scriși pe alte rânduri; coloana săptămânii
    // e prognoză, deci ei nu intră, altfel topul ar depăși totalul de jos.
    $piece(['line' => 'C10', 'kind' => 'invoice', 'label' => 'Plătit Deja', 'lei' => 6000000, 'actual' => true]);
    // Altă săptămână, altă socoteală.
    $piece(['line' => 'C10', 'kind' => 'invoice', 'label' => 'Furnizor de Luna Viitoare', 'week' => '2026-10-05', 'lei' => 7000000]);

    $digest = (new CashFlowDailyMail($snapshot))->digest;

    expect(array_column($digest['top_in'], 'label'))->toBe(['Agenția Mare', 'Agenția Mică'])
        ->and($digest['top_in'][0]['lei'])->toBe(710000.0)
        ->and($digest['top_in'][0]['pieces'])->toBe(3)
        // Două hârtii scrise pe larg, restul numărate.
        ->and($digest['top_in'][0]['documents'])->toHaveCount(2)
        ->and($digest['top_in'][0]['documents'][0]['reference'])->toBe('D-1')
        ->and($digest['top_in'][0]['documents'][0]['date'])->toBe('01.10.2026')
        ->and($digest['top_in'][0]['documents'][0]['currency'])->toBe('EUR')
        ->and($digest['top_in'][0]['rest'])->toBe(1)
        ->and(array_column($digest['top_out'], 'label'))->toBe(['Furnizor Greu', 'Furnizor Ușor'])
        // O mișcare de bancă n-are număr de document, are cont.
        ->and($digest['top_out'][1]['documents'][0]['bank'])->toBeTrue()
        ->and($digest['current_week'])->toBe(['from' => '28.09', 'to' => '04.10.2026']);

    $html = (new CashFlowDailyMail($snapshot))->render();

    expect($html)->toContain('Top 3 de încasat')->toContain('Agenția Mare')->toContain('D-1')
        ->toContain('Top 3 de plătit')->toContain('Furnizor Greu')
        ->not->toContain('Plătit Deja')
        ->not->toContain('Furnizor de Luna Viitoare');
});

test('on our own sales the client of the booking is us, so the group says what it is', function () {
    $snapshot = CashFlowSnapshot::factory()->create(['payload' => cashFlowPayload()]);
    $company = Company::factory()->create(['name' => 'Christian Tour']);

    CashFlowDetail::query()->create([
        'cash_flow_snapshot_id' => $snapshot->id,
        'week' => '2026-09-28',
        'actual' => false,
        'source' => 'test',
        'line' => 'B7',
        'kind' => 'tranche',
        'label' => $company->name,
        'reference' => '10078421',
        'lei' => 25000,
    ]);

    $digest = (new CashFlowDailyMail($snapshot))->digest;

    expect($digest['top_in'][0]['label'])->toBe('Clienți direcți (retail)');
});

test('with no recorded pieces the week block simply is not there', function () {
    $snapshot = CashFlowSnapshot::factory()->create(['payload' => cashFlowPayload()]);

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
