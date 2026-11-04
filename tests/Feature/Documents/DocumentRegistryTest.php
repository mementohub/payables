<?php

use App\Jobs\ReadDocumentFile;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentFile;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('documents.disk'));
    Queue::fake();

    $this->keeper = User::factory()->withRoles('contract_management')->create();
    $this->keeper->departments()->sync([Department::query()->whereNotNull('code')->orderBy('sort')->value('id')]);
    $this->colleague = User::factory()->withRoles('operational')->create();
    $this->boss = User::factory()->withRoles('admin')->create();
});

test('everybody reads the registers, but only the keeper changes them', function () {
    foreach (['decizii', 'proceduri'] as $slug) {
        $this->actingAs($this->keeper)->get("/documente/{$slug}")->assertOk();
        // Hârtiile astea sunt scrise ca să fie citite: le vede toată lumea.
        $this->actingAs($this->colleague)->get("/documente/{$slug}")->assertOk();

        $this->actingAs($this->colleague)
            ->post("/documente/{$slug}", ['files' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]])
            ->assertForbidden();
    }

    // Un registru care nu există nu se deschide, oricât ar ghici cineva.
    $this->actingAs($this->keeper)->get('/documente/altceva')->assertNotFound();
});

test('an uploaded file becomes a document, with its own series, and goes off to be read', function () {
    $this->actingAs($this->keeper)
        ->post('/documente/decizii', ['files' => [UploadedFile::fake()->create('Decizie concediu colectiv.pdf', 60, 'application/pdf')]])
        ->assertRedirect();

    $this->actingAs($this->keeper)
        ->post('/documente/proceduri', ['files' => [UploadedFile::fake()->create('Procedura de casare.pdf', 60, 'application/pdf')]])
        ->assertRedirect();

    $decision = CompanyDocument::query()->ofType(CompanyDocument::TYPE_DECISION)->firstOrFail();
    $procedure = CompanyDocument::query()->ofType(CompanyDocument::TYPE_PROCEDURE)->firstOrFail();

    // Fiecare registru are seria lui: numerele nu se încalecă.
    expect($decision->number)->toBe('DEC-'.now()->year.'-0001')
        ->and($procedure->number)->toBe('PRO-'.now()->year.'-0001')
        ->and($decision->title)->toBe('Decizie concediu colectiv')
        ->and($decision->status)->toBe(CompanyDocument::STATUS_ACTIVE)
        ->and($decision->owner_id)->toBe($this->keeper->id)
        // Netrimisă, nu cere nimănui nimic.
        ->and($decision->published_at)->toBeNull()
        ->and($decision->events()->where('type', 'uploaded')->exists())->toBeTrue();

    Storage::disk(config('documents.disk'))->assertExists($decision->files()->first()->path);
    Queue::assertPushed(ReadDocumentFile::class, 2);
});

test('the same file uploaded again is a new version, not a second document', function () {
    $file = UploadedFile::fake()->create('procedura.pdf', 40, 'application/pdf');

    $this->actingAs($this->keeper)->post('/documente/proceduri', ['files' => [$file]])->assertRedirect();
    $this->actingAs($this->keeper)->post('/documente/proceduri', ['files' => [
        UploadedFile::fake()->createWithContent('procedura.pdf', (string) file_get_contents($file->getRealPath())),
    ]])->assertRedirect();

    expect(CompanyDocument::query()->count())->toBe(1)
        ->and(CompanyDocumentFile::query()->count())->toBe(2)
        ->and(CompanyDocumentFile::query()->orderByDesc('version')->first()->version)->toBe(2);
});

test('the same number can live once in each series, but not twice in one', function () {
    $decision = CompanyDocument::query()->create(['type' => CompanyDocument::TYPE_DECISION, 'number' => 'DEC-2026-0001', 'title' => 'Una']);
    CompanyDocument::query()->create(['type' => CompanyDocument::TYPE_PROCEDURE, 'number' => 'DEC-2026-0001', 'title' => 'Alta']);
    $second = CompanyDocument::query()->create(['type' => CompanyDocument::TYPE_DECISION, 'number' => 'DEC-2026-0002', 'title' => 'A doua']);

    $this->actingAs($this->keeper)
        ->put("/documente/decizii/{$second->id}", [
            'number' => $decision->number,
            'title' => 'A doua',
            'status' => CompanyDocument::STATUS_ACTIVE,
        ])
        ->assertSessionHasErrors('number');
});

test('a new procedure pushes the old one out of use', function () {
    $old = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_PROCEDURE, 'number' => 'PRO-2025-0003',
        'title' => 'Casare, varianta veche', 'status' => CompanyDocument::STATUS_ACTIVE,
    ]);
    $new = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_PROCEDURE, 'number' => 'PRO-2026-0001',
        'title' => 'Casare', 'status' => CompanyDocument::STATUS_ACTIVE,
    ]);

    $this->actingAs($this->keeper)
        ->put("/documente/proceduri/{$new->id}", [
            'number' => $new->number,
            'title' => $new->title,
            'status' => CompanyDocument::STATUS_ACTIVE,
            'supersedes_id' => $old->id,
        ])
        ->assertRedirect();

    expect($old->fresh()->status)->toBe(CompanyDocument::STATUS_SUPERSEDED)
        ->and($new->fresh()->supersedes_id)->toBe($old->id);

    // Nu se poate înlocui pe sine.
    $this->actingAs($this->keeper)
        ->put("/documente/proceduri/{$new->id}", [
            'number' => $new->number, 'title' => $new->title,
            'status' => CompanyDocument::STATUS_ACTIVE, 'supersedes_id' => $new->id,
        ])
        ->assertSessionHasErrors('supersedes_id');
});

test('the search reaches into the text read out of the file', function () {
    $document = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_PROCEDURE, 'number' => 'PRO-2026-0007', 'title' => 'Ceva',
    ]);
    CompanyDocumentFile::query()->create([
        'company_document_id' => $document->id, 'path' => 'x.pdf', 'original_name' => 'x.pdf',
        'hash' => 'abc', 'text' => 'Decontul se depune în cel mult cinci zile lucrătoare.',
    ]);

    $this->actingAs($this->colleague)
        ->get('/documente/proceduri?search=cinci+zile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('documents.data', 1));

    $this->actingAs($this->colleague)
        ->get('/documente/proceduri?search=altceva+cu+totul')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('documents.data', 0));
});

test('only an administrator wipes a document, files and all', function () {
    $this->actingAs($this->keeper)
        ->post('/documente/decizii', ['files' => [UploadedFile::fake()->create('d.pdf', 10, 'application/pdf')]]);

    $document = CompanyDocument::query()->firstOrFail();
    $path = $document->files()->first()->path;

    $this->actingAs($this->keeper)->delete("/documente/decizii/{$document->id}")->assertForbidden();
    expect(CompanyDocument::query()->count())->toBe(1);

    $this->actingAs($this->boss)->delete("/documente/decizii/{$document->id}")->assertRedirect();

    expect(CompanyDocument::query()->count())->toBe(0);
    Storage::disk(config('documents.disk'))->assertMissing($path);
});

test('a document of one register does not open under the other', function () {
    $decision = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_DECISION, 'number' => 'DEC-2026-0009', 'title' => 'Decizie',
    ]);

    $this->actingAs($this->keeper)->get("/documente/decizii/{$decision->id}")->assertOk();
    $this->actingAs($this->keeper)->get("/documente/proceduri/{$decision->id}")->assertNotFound();
});
