<?php

use App\Mail\CashFlowDailyMail;
use App\Models\CashFlowSnapshot;
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
