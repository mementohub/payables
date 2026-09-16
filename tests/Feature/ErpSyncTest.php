<?php

use App\Jobs\SyncCompanyJob;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\RemoteConnection;
use App\Services\SyncService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

/**
 * @param  array<string, int>  $overrides
 * @return array<string, int>
 */
function syncResult(array $overrides = []): array
{
    return [
        'partners' => 2, 'invoices' => 5, 'details' => 9, 'bank_accounts' => 0, 'company_bank_accounts' => 0,
        'payments' => 3, 'statements' => 1, 'e_invoices' => 0, ...$overrides,
    ];
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['name' => 'Christian Tour']);
});

test('a sync without a range runs inline for the recent days', function () {
    Queue::fake();

    $this->mock(SyncService::class, function (MockInterface $mock) {
        $mock->shouldReceive('syncRecent')
            ->withArgs(fn (Company $company) => $company->is($this->company))
            ->once()
            ->andReturn(syncResult(['refreshed' => 1]));
    });

    $this->actingAs($this->user)
        ->from('/invoices/received')
        ->post("/companies/{$this->company->id}/sync")
        ->assertRedirect('/invoices/received');

    Queue::assertNothingPushed();
});

test('a short range runs inline, a long one goes to the queue', function () {
    Queue::fake();

    $this->mock(SyncService::class, function (MockInterface $mock) {
        $mock->shouldReceive('sync')
            ->withArgs(fn (Company $company, Carbon $from, Carbon $to) => $from->toDateString() === '2026-09-10' && $to->toDateString() === '2026-09-14')
            ->once()
            ->andReturn(syncResult());
    });

    $this->actingAs($this->user)
        ->post("/companies/{$this->company->id}/sync", ['from' => '2026-09-10', 'to' => '2026-09-14'])
        ->assertRedirect();

    Queue::assertNothingPushed();

    $this->actingAs($this->user)
        ->post("/companies/{$this->company->id}/sync", ['from' => '2026-01-01', 'to' => '2026-03-31'])
        ->assertRedirect();

    Queue::assertPushed(SyncCompanyJob::class, 1);
});

test('a failed inline sync reports the cause instead of a 500', function () {
    $this->mock(SyncService::class, function (MockInterface $mock) {
        $mock->shouldReceive('syncRecent')->once()->andThrow(new RuntimeException('connection to server at "chr-etrip-pgbouncer" failed'));
    });

    $this->actingAs($this->user)
        ->post("/companies/{$this->company->id}/sync")
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

test('sync-all pulls the recent days of every company', function () {
    Company::factory()->create(['name' => 'Vacanza']);

    $this->mock(SyncService::class, function (MockInterface $mock) {
        $mock->shouldReceive('syncRecent')->twice()->andReturn(syncResult(['refreshed' => 0]));
    });

    $this->actingAs($this->user)
        ->post('/companies/sync')
        ->assertRedirect();
});

test('the erp:sync command syncs every company and keeps going after a failure', function () {
    $broken = Company::factory()->create(['name' => 'Broken']);

    $this->mock(SyncService::class, function (MockInterface $mock) use ($broken) {
        $mock->shouldReceive('syncRecent')
            ->twice()
            ->andReturnUsing(function (Company $company) use ($broken) {
                if ($company->is($broken)) {
                    throw new RuntimeException('no route to host');
                }

                return syncResult(['refreshed' => 4]);
            });
    });

    $this->artisan('erp:sync')
        ->expectsOutputToContain('no route to host')
        ->assertFailed();
});

test('the erp:sync command accepts an explicit range', function () {
    $this->mock(SyncService::class, function (MockInterface $mock) {
        $mock->shouldReceive('sync')
            ->withArgs(fn (Company $company, Carbon $from, Carbon $to) => $from->toDateString() === '2026-08-01' && $to->toDateString() === '2026-08-31')
            ->once()
            ->andReturn(syncResult());
    });

    $this->artisan('erp:sync', ['--company' => $this->company->id, '--from' => '2026-08-01', '--to' => '2026-08-31'])->assertSuccessful();
});

test('open invoices are refreshed from the erp by their document key', function () {
    $partner = Partner::factory()->for($this->company)->create();
    $paidMeanwhile = Invoice::factory()->for($this->company)->for($partner)->create(['data_doc' => '2026-08-05', 'nr_doc' => 'A', 'val_mon' => 1000]);
    $stillOpen = Invoice::factory()->for($this->company)->for($partner)->create(['data_doc' => '2026-08-06', 'nr_doc' => 'B', 'val_mon' => 500]);
    Invoice::factory()->for($this->company)->for($partner)->create(['data_doc' => '2026-08-07', 'nr_doc' => 'C', 'val_mon' => 300, 'val_mon_paid' => 300]);
    Invoice::factory()->create(['data_doc' => '2026-08-08', 'nr_doc' => 'OTHER', 'val_mon' => 100]);

    $remote = Mockery::mock(ConnectionInterface::class);
    $remote->shouldReceive('select')->andReturnUsing(function (string $sql, array $bindings) {
        if (str_contains($sql, 'from doc_fin')) {
            expect($bindings)->toBe(['2026-08-05', 'FactFI', 'A']);

            return [(object) [
                'data_doc_com' => '2026-08-05', 'tip_doc_com' => 'FactFI', 'nr_doc_com' => 'A',
                'data_doc_fin' => '2026-09-15', 'tip_doc_fin' => 'OP_PL', 'nr_doc_fin' => 'BTRL1',
                'data_repartizare' => '2026-09-15', 'val_fin' => 1000, 'val_com' => 1000, 'fin_moneda' => 'Lei',
            ]];
        }

        expect($bindings)->toBe(['2026-08-05', 'FactFI', 'A', '2026-08-06', 'FactFI', 'B']);

        return [
            (object) ['data_doc' => '2026-08-05', 'tip_doc' => 'FactFI', 'nr_doc' => 'A', 'val_mon' => 1000, 'val_mon_inc' => 0, 'val_mon_pl' => 1000, 'val_mon_dimin_negru' => 0, 'data_scadenta' => '2026-09-04'],
            (object) ['data_doc' => '2026-08-06', 'tip_doc' => 'FactFI', 'nr_doc' => 'B', 'val_mon' => 500, 'val_mon_inc' => 0, 'val_mon_pl' => 0, 'val_mon_dimin_negru' => 0, 'data_scadenta' => null],
        ];
    });

    $this->mock(RemoteConnection::class, function (MockInterface $mock) use ($remote) {
        $mock->shouldReceive('connection')->andReturn($remote);
    });

    $updated = app(SyncService::class)->refreshOpenInvoices($this->company);

    expect($updated)->toBe(1)
        ->and((float) $paidMeanwhile->fresh()->val_mon_paid)->toBe(1000.0)
        ->and($paidMeanwhile->fresh()->data_scadenta?->toDateString())->toBe('2026-09-04')
        ->and($paidMeanwhile->payments()->count())->toBe(1)
        ->and($paidMeanwhile->payments()->first()->nr_doc)->toBe('BTRL1')
        ->and((float) $stillOpen->fresh()->val_mon_paid)->toBe(0.0);
});
