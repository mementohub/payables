<?php

use App\Jobs\ReadContractFile;
use App\Mail\ContractsSharedMail;
use App\Models\Contract;
use App\Models\ContractFile;
use App\Models\ContractShare;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('contracts.disk'));
    Mail::fake();

    $this->keeper = User::factory()->withRoles('contract_management')->create();
    $this->other = User::factory()->withRoles('contract_management')->create();
});

/** Câteva contracte ale aceluiași om. */
function few(User $of, int $count = 3): array
{
    $made = [];

    for ($i = 1; $i <= $count; $i++) {
        $made[] = Contract::query()->create([
            'number' => 'CTR-2026-02'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'title' => 'Contract '.$i,
            'partner_name' => 'Partener '.$i,
            'status' => Contract::STATUS_ACTIVE,
            'created_by_id' => $of->id,
        ]);
    }

    return $made;
}

test('a stack of contracts goes out in a single mail to each address', function () {
    $contracts = few($this->keeper);

    $this->actingAs($this->keeper)->post('/contracts-bulk', [
        'action' => 'share',
        'ids' => collect($contracts)->pluck('id')->all(),
        'emails' => 'unul@christiantour.ro, altul@christiantour.ro',
        'permission' => 'view',
        'days' => 15,
    ])->assertRedirect();

    // Doi oameni, câte un mail fiecare — nu șase.
    Mail::assertSentCount(2);
    Mail::assertSent(ContractsSharedMail::class, fn ($mail) => $mail->shares->count() === 3);

    expect(ContractShare::query()->count())->toBe(6)
        ->and(ContractShare::query()->pluck('token')->unique()->count())->toBe(6);
});

test('the department and the owner can be set on the whole stack at once', function () {
    $contracts = few($this->keeper);
    $department = Department::query()->whereNotNull('code')->first();
    $ids = collect($contracts)->pluck('id')->all();

    $this->actingAs($this->keeper)->post('/contracts-bulk', [
        'action' => 'department', 'ids' => $ids, 'department_id' => $department->id,
    ])->assertRedirect();

    $this->actingAs($this->keeper)->post('/contracts-bulk', [
        'action' => 'owner', 'ids' => $ids, 'owner_id' => $this->other->id,
    ])->assertRedirect();

    expect(Contract::query()->whereIn('id', $ids)->where('department_id', $department->id)->count())->toBe(3)
        ->and(Contract::query()->whereIn('id', $ids)->where('owner_id', $this->other->id)->count())->toBe(3);
});

test('what is not yours stays out of the stack', function () {
    $mine = few($this->keeper, 2);
    $his = Contract::query()->create([
        'number' => 'CTR-2026-0299', 'title' => 'Al lui', 'partner_name' => 'Altcineva',
        'created_by_id' => $this->other->id,
    ]);

    $this->actingAs($this->keeper)->post('/contracts-bulk', [
        'action' => 'archive',
        'ids' => [...collect($mine)->pluck('id')->all(), $his->id],
    ])->assertRedirect();

    expect(Contract::query()->whereIn('id', collect($mine)->pluck('id'))->whereNotNull('archived_at')->count())->toBe(2)
        // Contractul altuia n-a fost atins, deși i s-a cerut numărul.
        ->and($his->refresh()->archived_at)->toBeNull();
});

test('only an admin may delete a stack', function () {
    $contracts = few($this->keeper, 2);
    $ids = collect($contracts)->pluck('id')->all();

    $this->actingAs($this->keeper)
        ->post('/contracts-bulk', ['action' => 'delete', 'ids' => $ids])
        ->assertForbidden();

    expect(Contract::query()->whereIn('id', $ids)->count())->toBe(2);

    $admin = User::factory()->withRoles('admin')->create();

    $this->actingAs($admin)
        ->post('/contracts-bulk', ['action' => 'delete', 'ids' => $ids])
        ->assertRedirect();

    expect(Contract::query()->whereIn('id', $ids)->count())->toBe(0);
});

test('a re-read is asked only for contracts that have a document', function () {
    Queue::fake();

    $contracts = few($this->keeper, 2);
    ContractFile::query()->create([
        'contract_id' => $contracts[0]->id, 'kind' => ContractFile::KIND_CONTRACT, 'version' => 1,
        'path' => 'a.pdf', 'original_name' => 'contract.pdf', 'hash' => 'b1',
    ]);

    $this->actingAs($this->keeper)->post('/contracts-bulk', [
        'action' => 'reread', 'ids' => collect($contracts)->pluck('id')->all(),
    ])->assertRedirect();

    Queue::assertPushed(ReadContractFile::class, 1);
});
