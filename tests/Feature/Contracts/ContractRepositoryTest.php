<?php

use App\Jobs\ReadContractFile;
use App\Mail\ContractSharedMail;
use App\Models\Contract;
use App\Models\ContractFile;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('contracts.disk'));
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
        // Ce se încarcă e, de obicei, un contract în lucru.
        ->and($contract->status)->toBe(Contract::STATUS_ACTIVE)
        ->and($contract->files()->count())->toBe(1)
        ->and($contract->files()->first()->version)->toBe(1)
        // Cine l-a încărcat răspunde de el, până îl trece altcuiva.
        ->and($contract->owner_id)->toBe($this->keeper->id)
        ->and($contract->events()->where('type', 'uploaded')->exists())->toBeTrue();

    Storage::disk(config('contracts.disk'))->assertExists($contract->files()->first()->path);
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

test('a contract that expires before it was signed can still be corrected', function () {
    // Așa vin unele documente din citire: o dată prinsă greșit. Dacă formularul
    // refuză să salveze din cauza ei, omul rămâne și fără departament, și fără
    // putința de a îndrepta data.
    $department = Department::query()->whereNotNull('code')->first();
    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0026', 'title' => 'Parenting', 'partner_name' => 'All About Parenting Systems S.R.L.',
        'status' => Contract::STATUS_ACTIVE, 'signed_at' => '2025-09-01', 'expires_at' => '2025-04-30',
        'created_by_id' => $this->keeper->id,
    ]);

    $this->actingAs($this->keeper)->put("/contracts/{$contract->id}", [
        'title' => 'Parenting',
        'partner_name' => 'All About Parenting Systems S.R.L.',
        'kind' => 'supplier',
        'department_id' => $department->id,
        'signed_at' => '2025-09-01',
        'expires_at' => '2025-04-30',
        'status' => Contract::STATUS_ACTIVE,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($contract->refresh()->department_id)->toBe($department->id);
});

test('the data of a contract can be corrected, and the change is kept in the log', function () {
    $department = Department::query()->whereNotNull('code')->first();
    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0009', 'title' => 'Vechi', 'partner_name' => 'Necunoscut', 'status' => Contract::STATUS_DRAFT,
        'created_by_id' => $this->keeper->id,
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

    Contract::query()->create(['number' => 'CTR-1', 'title' => 'Charter', 'partner_name' => 'Memento Air SRL', 'kind' => 'group', 'department_id' => $department->id, 'status' => Contract::STATUS_ACTIVE, 'signed_at' => '2026-02-12', 'expires_at' => '2026-10-31', 'value' => 100000, 'currency' => 'EUR', 'created_by_id' => $this->keeper->id]);
    Contract::query()->create(['number' => 'CTR-2', 'title' => 'Combustibil', 'partner_name' => 'DKV Euro Service', 'kind' => 'supplier', 'status' => Contract::STATUS_ACTIVE, 'signed_at' => '2025-09-01', 'expires_at' => '2027-08-31', 'value' => 1200000, 'currency' => 'RON', 'created_by_id' => $this->keeper->id]);

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
    $contract = Contract::query()->create(['number' => 'CTR-2026-0011', 'title' => 'Test', 'partner_name' => 'Hotel Alfa', 'created_by_id' => $this->keeper->id]);

    $this->actingAs($this->keeper)->post("/contracts/{$contract->id}/share", [
        'emails' => 'nina.seretean@christiantour.ro, nu-e-mail, legal@christiantour.ro',
        'permission' => 'view',
    ])->assertRedirect();

    $shares = $contract->shares()->get();

    expect($shares)->toHaveCount(2)
        ->and($shares->pluck('email')->all())->toContain('legal@christiantour.ro')
        ->and($shares->first()->token)->not->toBeEmpty()
        ->and($shares->first()->expires_at?->isFuture())->toBeTrue()
        // Nespus, legătura ține cât scrie în configurare: 15 zile.
        ->and($shares->first()->expires_at?->toDateString())
        ->toBe(now()->addDays(config('contracts.share_days'))->toDateString())
        ->and($contract->events()->where('type', 'shared')->exists())->toBeTrue();
});

test('an archived contract leaves the list but stays in the repository', function () {
    $contract = Contract::query()->create(['number' => 'CTR-2026-0012', 'title' => 'Test', 'partner_name' => 'Hotel Alfa', 'status' => Contract::STATUS_ACTIVE, 'created_by_id' => $this->keeper->id]);

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

test('only an admin wipes a contract, and its files go with it', function () {
    $admin = User::factory()->withRoles(['contract_management', 'admin'])->create();

    $this->actingAs($this->keeper)
        ->post('/contracts', ['files' => [UploadedFile::fake()->create('de-sters.pdf', 40, 'application/pdf')]])
        ->assertRedirect();

    $contract = Contract::query()->firstOrFail();
    $path = $contract->files()->first()->path;

    // Cine ține repertoriul arhivează, dar nu șterge.
    $this->actingAs($this->keeper)->delete("/contracts/{$contract->id}")->assertForbidden();
    expect(Contract::query()->count())->toBe(1);

    $this->actingAs($admin)->delete("/contracts/{$contract->id}")->assertRedirect('/contracts');

    expect(Contract::query()->count())->toBe(0)
        ->and(ContractFile::query()->count())->toBe(0);

    Storage::disk(config('contracts.disk'))->assertMissing($path);
});

test('a Word contract signed with a picture is taken in, like any other', function () {
    foreach (['contract.docx', 'contract semnat.doc', 'scan.jpg', 'anexa.rtf'] as $name) {
        $this->actingAs($this->keeper)
            ->post('/contracts', ['files' => [UploadedFile::fake()->createWithContent($name, 'conținutul lui '.$name)]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    expect(Contract::query()->count())->toBe(4);
});

test('a file in a format nobody can read is refused, with a reason', function () {
    $this->actingAs($this->keeper)
        ->post('/contracts', ['files' => [UploadedFile::fake()->create('virus.exe', 10)]])
        ->assertSessionHasErrors('files.0');

    expect(Contract::query()->count())->toBe(0);
});

test('a submission that arrives without its files says why, not "field required"', function () {
    // Trimiterea ajunge cu greutate, dar fără niciun fișier: PHP le-a aruncat
    // pe drum. „Câmpul files este obligatoriu” n-ar lămuri pe nimeni.
    $this->actingAs($this->keeper)
        ->withServerVariables(['CONTENT_LENGTH' => 1024 * 1024])
        ->post('/contracts', [])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Contract::query()->count())->toBe(0);
});

test('the page says how much the server takes, before anyone tries', function () {
    $limits = $this->actingAs($this->keeper)->get('/contracts')
        ->viewData('page')['props']['limits'];

    expect($limits['upload_mb'])->toBeGreaterThan(0)
        ->and($limits['post'])->not->toBeEmpty();
});

test('a keeper of contracts sees only his own; Top Management and the admin see them all', function () {
    $other = User::factory()->withRoles('contract_management')->create();
    $boss = User::factory()->withRoles('top_management')->create();
    $admin = User::factory()->withRoles('admin')->create();

    $mine = Contract::query()->create([
        'number' => 'CTR-A', 'title' => 'Al meu', 'partner_name' => 'Hotel Alfa',
        'created_by_id' => $this->keeper->id, 'owner_id' => $this->keeper->id,
    ]);
    $his = Contract::query()->create([
        'number' => 'CTR-B', 'title' => 'Al lui', 'partner_name' => 'Hotel Beta',
        'created_by_id' => $other->id, 'owner_id' => $other->id,
    ]);
    // Adus de unul, dat în grija altuia: îl văd amândoi.
    $shared = Contract::query()->create([
        'number' => 'CTR-C', 'title' => 'Trecut altuia', 'partner_name' => 'Hotel Gama',
        'created_by_id' => $other->id, 'owner_id' => $this->keeper->id,
    ]);

    $seen = fn (User $user) => collect(
        $this->actingAs($user)->get('/contracts')->viewData('page')['props']['contracts']['data']
    )->pluck('number')->sort()->values()->all();

    expect($seen($this->keeper))->toBe(['CTR-A', 'CTR-C'])
        ->and($seen($other))->toBe(['CTR-B', 'CTR-C'])
        ->and($seen($boss))->toBe(['CTR-A', 'CTR-B', 'CTR-C'])
        ->and($seen($admin))->toBe(['CTR-A', 'CTR-B', 'CTR-C']);

    // Nici pe ocolite: contractul altuia nu se deschide și nu se descarcă.
    $this->actingAs($this->keeper)->get("/contracts/{$his->id}")->assertForbidden();
    $this->actingAs($this->keeper)->put("/contracts/{$his->id}", [
        'title' => 'x', 'partner_name' => 'y', 'kind' => 'supplier', 'status' => 'draft',
    ])->assertForbidden();
    $this->actingAs($boss)->get("/contracts/{$mine->id}")->assertOk();
    $this->actingAs($this->keeper)->get("/contracts/{$shared->id}")->assertOk();
});

test('the numbers at the top count what the person can see', function () {
    $other = User::factory()->withRoles('contract_management')->create();

    Contract::query()->create(['number' => 'CTR-A', 'title' => 'a', 'partner_name' => 'x', 'status' => 'active', 'created_by_id' => $this->keeper->id]);
    Contract::query()->create(['number' => 'CTR-B', 'title' => 'b', 'partner_name' => 'y', 'status' => 'active', 'created_by_id' => $other->id]);

    $summary = fn (User $user) => $this->actingAs($user)->get('/contracts')
        ->viewData('page')['props']['summary'];

    expect($summary($this->keeper)['active'])->toBe(1)
        ->and($summary(User::factory()->withRoles('top_management')->create())['active'])->toBe(2);
});

test('sharing a contract sends the mail and opens from the link, without an account', function () {
    Mail::fake();

    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0021', 'title' => 'Charter', 'partner_name' => 'Memento Air',
        'created_by_id' => $this->keeper->id, 'value' => 1000, 'currency' => 'EUR',
    ]);
    ContractFile::query()->create([
        'contract_id' => $contract->id, 'path' => 'x.pdf', 'original_name' => 'contract.pdf',
        'hash' => 'abc', 'mime' => 'application/pdf',
    ]);

    $this->actingAs($this->keeper)->post("/contracts/{$contract->id}/share", [
        'emails' => 'nina.seretean@christiantour.ro',
        'permission' => 'view',
        'days' => 7,
        'note' => 'Te rog uită-te peste art. 9.',
    ])->assertRedirect();

    $share = $contract->shares()->firstOrFail();

    Mail::assertSent(ContractSharedMail::class, function ($mail) use ($share) {
        return $mail->hasTo('nina.seretean@christiantour.ro')
            && $mail->share->is($share)
            && $mail->note === 'Te rog uită-te peste art. 9.';
    });

    // Legătura din mail se deschide fără cont și se numără.
    $this->get("/contracte/{$share->token}")->assertOk();

    expect($share->fresh()->opens)->toBe(1)
        ->and($contract->events()->where('type', 'opened')->exists())->toBeTrue();

    // Stinsă, nu mai deschide nimic.
    $share->forceFill(['expires_at' => now()->subDay()])->save();
    $this->get("/contracte/{$share->token}")->assertNotFound();
    $this->get('/contracte/habar-n-am-ce-token')->assertNotFound();
});

test('a contract sent to a colleague opens for him in the application too', function () {
    $colleague = User::factory()->withRoles('contract_management')->create();
    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0022', 'title' => 'Test', 'partner_name' => 'Hotel Alfa',
        'created_by_id' => $this->keeper->id,
    ]);

    $this->actingAs($colleague)->get("/contracts/{$contract->id}")->assertForbidden();

    $contract->shares()->create([
        'email' => $colleague->email, 'user_id' => $colleague->id,
        'permission' => 'edit', 'token' => 'un-token-oarecare-lung',
    ]);

    $this->actingAs($colleague)->get("/contracts/{$contract->id}")->assertOk();
});

test('an addendum sits beside the contract, it does not replace it', function () {
    $this->actingAs($this->keeper)
        ->post('/contracts', ['files' => [UploadedFile::fake()->createWithContent('contract.pdf', 'contractul de bază')]])
        ->assertRedirect();

    $contract = Contract::query()->firstOrFail();

    $this->actingAs($this->keeper)->post("/contracts/{$contract->id}/files", [
        'files' => [UploadedFile::fake()->createWithContent('act aditional 1.pdf', 'actul adițional')],
        'kind' => 'addendum',
        'label' => 'nr. 1',
        'signed_at' => '2027-01-15',
    ])->assertRedirect();

    $contract->refresh()->load('files');
    $addendum = $contract->files->firstWhere('kind', ContractFile::KIND_ADDENDUM);

    expect($contract->files)->toHaveCount(2)
        // Contractul rămâne la versiunea lui: actul adițional nu e „v2”.
        ->and($contract->current()->kind)->toBe(ContractFile::KIND_CONTRACT)
        ->and($contract->current()->version)->toBe(1)
        ->and($addendum->version)->toBe(1)
        ->and($addendum->title())->toBe('Act adițional nr. 1')
        ->and($addendum->signed_at?->toDateString())->toBe('2027-01-15')
        ->and($contract->addenda())->toHaveCount(1)
        ->and($contract->events()->where('type', 'addendum')->exists())->toBeTrue();

    // O versiune nouă a contractului însuși se numerotează mai departe.
    $this->actingAs($this->keeper)->post("/contracts/{$contract->id}/files", [
        'files' => [UploadedFile::fake()->createWithContent('contract semnat.pdf', 'exemplarul semnat')],
        'kind' => 'contract',
    ])->assertRedirect();

    expect($contract->fresh()->current()->version)->toBe(2);
});

test('a question looks in the addenda too, and says which paper answers it', function () {
    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0050', 'title' => 'Prestări', 'partner_name' => 'BVB',
        'created_by_id' => $this->keeper->id,
    ]);

    ContractFile::query()->create([
        'contract_id' => $contract->id, 'kind' => ContractFile::KIND_CONTRACT, 'version' => 1,
        'path' => 'a.pdf', 'original_name' => 'contract.pdf', 'hash' => 'h1',
        'text' => "3.1. Pretul serviciilor este de 1.000 EUR pe luna.\n\n3.2. Plata se face in 30 de zile.",
    ]);
    ContractFile::query()->create([
        'contract_id' => $contract->id, 'kind' => ContractFile::KIND_ADDENDUM, 'version' => 1, 'label' => 'nr. 1',
        'path' => 'b.pdf', 'original_name' => 'act.pdf', 'hash' => 'h2', 'signed_at' => '2027-01-15',
        'text' => '1. Incepand cu 01.02.2027, pretul serviciilor se majoreaza la 1.250 EUR pe luna.',
    ]);

    $answers = $this->actingAs($this->keeper)
        ->postJson("/contracts/{$contract->id}/ask", ['question' => 'care este pretul serviciilor?'])
        ->json('answers');

    // Actul adițional răspunde primul: el a schimbat prețul.
    expect($answers[0]['document'])->toBe('Act adițional nr. 1')
        ->and($answers[0]['text'])->toContain('1.250')
        ->and(collect($answers)->pluck('document')->all())->toContain('Contract v1');
});

test('a whole stack of annexes goes up at once', function () {
    $this->actingAs($this->keeper)
        ->post('/contracts', ['files' => [UploadedFile::fake()->createWithContent('contract.pdf', 'contractul')]])
        ->assertRedirect();

    $contract = Contract::query()->firstOrFail();

    $this->actingAs($this->keeper)->post("/contracts/{$contract->id}/files", [
        'files' => [
            UploadedFile::fake()->createWithContent('anexa 1.pdf', 'grila de preturi'),
            UploadedFile::fake()->createWithContent('anexa 2.pdf', 'caiet de sarcini'),
            UploadedFile::fake()->createWithContent('anexa 3.pdf', 'lista de servicii'),
        ],
        'kind' => 'annex',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $contract->refresh()->load('files');

    expect($contract->files)->toHaveCount(4)
        ->and($contract->files->where('kind', ContractFile::KIND_ANNEX))->toHaveCount(3)
        // Niciuna nu e „versiunea 2” a contractului.
        ->and($contract->current()->version)->toBe(1)
        ->and($contract->addenda())->toHaveCount(3);
});

test('the list can be ordered by any column it shows', function () {
    $department = Department::query()->whereNotNull('code')->first();

    Contract::query()->create(['number' => 'CTR-B', 'title' => 'Beta', 'partner_name' => 'Zodiac SRL', 'value' => 100, 'currency' => 'EUR', 'signed_at' => '2026-01-05', 'expires_at' => '2027-05-05', 'status' => 'active', 'created_by_id' => $this->keeper->id]);
    Contract::query()->create(['number' => 'CTR-A', 'title' => 'Alfa', 'partner_name' => 'Alfa SRL', 'value' => 900, 'currency' => 'EUR', 'signed_at' => '2026-03-09', 'expires_at' => '2027-01-01', 'status' => 'draft', 'created_by_id' => $this->keeper->id, 'department_id' => $department->id]);
    // Fără termen: stă la coadă, oricum ar fi rânduită lista.
    Contract::query()->create(['number' => 'CTR-C', 'title' => 'Gama', 'partner_name' => 'Mamut SRL', 'status' => 'active', 'created_by_id' => $this->keeper->id]);

    $order = fn (array $query) => collect(
        $this->actingAs($this->keeper)->get('/contracts?'.http_build_query($query))
            ->viewData('page')['props']['contracts']['data']
    )->pluck('number')->all();

    expect($order(['sort' => 'number', 'dir' => 'asc']))->toBe(['CTR-A', 'CTR-B', 'CTR-C'])
        ->and($order(['sort' => 'number', 'dir' => 'desc']))->toBe(['CTR-C', 'CTR-B', 'CTR-A'])
        ->and($order(['sort' => 'partner', 'dir' => 'asc']))->toBe(['CTR-A', 'CTR-C', 'CTR-B'])
        ->and($order(['sort' => 'value', 'dir' => 'desc']))->toBe(['CTR-A', 'CTR-B', 'CTR-C'])
        ->and($order(['sort' => 'signed', 'dir' => 'asc']))->toBe(['CTR-B', 'CTR-A', 'CTR-C'])
        // Cel fără scadență rămâne ultimul, și la suit, și la coborât.
        ->and($order(['sort' => 'expires', 'dir' => 'asc']))->toBe(['CTR-A', 'CTR-B', 'CTR-C'])
        ->and($order(['sort' => 'expires', 'dir' => 'desc']))->toBe(['CTR-B', 'CTR-A', 'CTR-C'])
        // Fără alegere, întâi ce expiră mai repede.
        ->and($order([]))->toBe(['CTR-A', 'CTR-B', 'CTR-C'])
        // Departamentul se rânduiește după nume, iar cei fără departament stau la coadă.
        ->and($order(['sort' => 'department', 'dir' => 'asc'])[0])->toBe('CTR-A');
});
