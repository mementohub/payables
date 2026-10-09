<?php

use App\Jobs\ReadContractFile;
use App\Models\Contract;
use App\Models\ContractFile;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();

    $this->keeper = User::factory()->withRoles('contract_management')->create();
    $this->outsider = User::factory()->withRoles('operational')->create();
});

test('contracts are for the role that keeps them, and nobody else', function () {
    $this->actingAs($this->keeper)->get('/contracts')->assertOk();
    $this->actingAs($this->outsider)->get('/contracts')->assertForbidden();

    // Nici măcar fișierul: dreptul se cere la fiecare descărcare.
    $contract = Contract::query()->create(['number' => 'CTR-2026-0001', 'title' => 'Test', 'partner_name' => 'Hotel Alfa']);
    $file = ContractFile::query()->create([
        'contract_id' => $contract->id, 'path' => 'contracts/1/x.pdf', 'original_name' => 'x.pdf', 'hash' => 'abc',
    ]);

    $this->actingAs($this->outsider)->get("/contracts/{$contract->id}/files/{$file->id}")->assertForbidden();
});

test('an uploaded file becomes a contract, with its number, and goes off to be read', function () {
    $this->actingAs($this->keeper)
        ->post('/contracts', ['files' => [UploadedFile::fake()->create('Contract Memento Air.pdf', 120, 'application/pdf')]])
        ->assertRedirect();

    $contract = Contract::query()->firstOrFail();

    expect($contract->number)->toBe('CTR-'.now()->year.'-0001')
        ->and($contract->title)->toBe('Contract Memento Air')
        ->and($contract->status)->toBe(Contract::STATUS_DRAFT)
        ->and($contract->files()->count())->toBe(1)
        ->and($contract->files()->first()->version)->toBe(1)
        ->and($contract->events()->where('type', 'uploaded')->exists())->toBeTrue();

    Storage::disk('local')->assertExists($contract->files()->first()->path);
    Queue::assertPushed(ReadContractFile::class);
});

test('the same file uploaded again is a new version, not a second contract', function () {
    $file = UploadedFile::fake()->create('contract.pdf', 80, 'application/pdf');

    $this->actingAs($this->keeper)->post('/contracts', ['files' => [$file]])->assertRedirect();
    $this->actingAs($this->keeper)->post('/contracts', ['files' => [
        UploadedFile::fake()->createWithContent('contract.pdf', file_get_contents($file->getRealPath())),
    ]])->assertRedirect();

    expect(Contract::query()->count())->toBe(1)
        ->and(ContractFile::query()->count())->toBe(2)
        ->and(ContractFile::query()->orderByDesc('version')->first()->version)->toBe(2);
});

test('the data of a contract can be corrected, and the change is kept in the log', function () {
    $department = Department::query()->whereNotNull('code')->first();
    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0009', 'title' => 'Vechi', 'partner_name' => 'Necunoscut', 'status' => Contract::STATUS_DRAFT,
    ]);

    $this->actingAs($this->keeper)->put("/contracts/{$contract->id}", [
        'title' => 'Charter S26',
        'partner_name' => 'Memento Air SRL',
        'kind' => 'group',
        'department_id' => $department->id,
        'owner_id' => $this->keeper->id,
        'value' => 18400000,
        'currency' => 'EUR',
        'signed_at' => '2026-02-12',
        'expires_at' => '2026-10-31',
        'notice_days' => 30,
        'status' => Contract::STATUS_ACTIVE,
    ])->assertRedirect();

    $contract->refresh();

    expect($contract->partner_name)->toBe('Memento Air SRL')
        ->and($contract->status)->toBe(Contract::STATUS_ACTIVE)
        ->and($contract->noticeOn()?->toDateString())->toBe('2026-10-01')
        ->and($contract->events()->where('type', 'updated')->exists())->toBeTrue();
});

test('the list filters on what the repository is asked for', function () {
    $department = Department::query()->whereNotNull('code')->first();

    Contract::query()->create(['number' => 'CTR-1', 'title' => 'Charter', 'partner_name' => 'Memento Air SRL', 'kind' => 'group', 'department_id' => $department->id, 'status' => Contract::STATUS_ACTIVE, 'signed_at' => '2026-02-12', 'expires_at' => '2026-10-31', 'value' => 100000, 'currency' => 'EUR']);
    Contract::query()->create(['number' => 'CTR-2', 'title' => 'Combustibil', 'partner_name' => 'DKV Euro Service', 'kind' => 'supplier', 'status' => Contract::STATUS_ACTIVE, 'signed_at' => '2025-09-01', 'expires_at' => '2027-08-31', 'value' => 1200000, 'currency' => 'RON']);

    $numbers = fn (array $query) => collect(
        $this->actingAs($this->keeper)->get('/contracts?'.http_build_query($query))
            ->viewData('page')['props']['contracts']['data']
    )->pluck('number')->all();

    expect($numbers(['search' => 'Memento']))->toBe(['CTR-1'])
        ->and($numbers(['kind' => 'supplier']))->toBe(['CTR-2'])
        ->and($numbers(['department_id' => $department->id]))->toBe(['CTR-1'])
        ->and($numbers(['expires_to' => '2026-12-31']))->toBe(['CTR-1'])
        ->and($numbers(['signed_from' => '2026-01-01']))->toBe(['CTR-1'])
        ->and($numbers(['value_from' => 500000]))->toBe(['CTR-2']);
});

test('a contract is sent as a link with a right and a term, not as a file', function () {
    $contract = Contract::query()->create(['number' => 'CTR-2026-0011', 'title' => 'Test', 'partner_name' => 'Hotel Alfa']);

    $this->actingAs($this->keeper)->post("/contracts/{$contract->id}/share", [
        'emails' => 'nina.seretean@christiantour.ro, nu-e-mail, legal@christiantour.ro',
        'permission' => 'view',
        'days' => 7,
    ])->assertRedirect();

    $shares = $contract->shares()->get();

    expect($shares)->toHaveCount(2)
        ->and($shares->pluck('email')->all())->toContain('legal@christiantour.ro')
        ->and($shares->first()->token)->not->toBeEmpty()
        ->and($shares->first()->expires_at?->isFuture())->toBeTrue()
        ->and($contract->events()->where('type', 'shared')->exists())->toBeTrue();
});

test('an archived contract leaves the list but stays in the repository', function () {
    $contract = Contract::query()->create(['number' => 'CTR-2026-0012', 'title' => 'Test', 'partner_name' => 'Hotel Alfa', 'status' => Contract::STATUS_ACTIVE]);

    $this->actingAs($this->keeper)->post("/contracts/{$contract->id}/archive")->assertRedirect();

    expect($contract->fresh()->archived_at)->not->toBeNull();

    $visible = fn (array $query = []) => collect(
        $this->actingAs($this->keeper)->get('/contracts?'.http_build_query($query))
            ->viewData('page')['props']['contracts']['data']
    )->pluck('number')->all();

    expect($visible())->toBe([])
        ->and($visible(['archived' => 1]))->toBe(['CTR-2026-0012']);
});

test('a signed contract past its term reads as expired, whatever the column says', function () {
    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0013', 'title' => 'Test', 'partner_name' => 'Hotel Alfa',
        'status' => Contract::STATUS_ACTIVE, 'expires_at' => now()->subDay()->toDateString(),
    ]);

    expect($contract->state())->toBe(Contract::STATUS_EXPIRED)
        ->and($contract->daysLeft())->toBeLessThan(0);
});
