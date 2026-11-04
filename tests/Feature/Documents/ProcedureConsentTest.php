<?php

use App\Mail\ConsentReceiptMail;
use App\Mail\DocumentNoticeMail;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentFile;
use App\Models\DocumentConsent;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('documents.disk'));
    Mail::fake();

    config(['documents.consent.group' => 'toata-lumea@christiantour.ro']);
    config(['documents.consent.domains' => ['christiantour.ro', 'mementogroup.com']]);

    $this->keeper = User::factory()->withRoles('contract_management')->create();
    $this->colleague = User::factory()->withRoles('operational')->create(['email' => 'ioana.vlad@christiantour.ro']);

    $this->procedure = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_PROCEDURE,
        'number' => 'PRO-2026-0001',
        'title' => 'Procedura de decontare a cheltuielilor de deplasare',
        'status' => CompanyDocument::STATUS_ACTIVE,
        'issued_at' => '2026-10-01',
    ]);
    CompanyDocumentFile::query()->create([
        'company_document_id' => $this->procedure->id,
        'path' => 'p.txt', 'original_name' => 'procedura.txt', 'hash' => 'h1',
        'text' => 'Decontul se depune în cel mult cinci zile lucrătoare de la întoarcere.',
    ]);
});

test('sending a procedure to the company mail group opens the way to consent', function () {
    $this->actingAs($this->keeper)
        ->post("/documente/proceduri/{$this->procedure->id}/trimite", ['due' => '2026-11-20'])
        ->assertRedirect();

    $procedure = $this->procedure->fresh();

    expect($procedure->token)->not->toBeNull()
        ->and($procedure->published_at)->not->toBeNull()
        ->and($procedure->sent_to)->toBe('toata-lumea@christiantour.ro')
        ->and($procedure->consent_due_at->toDateString())->toBe('2026-11-20')
        ->and($procedure->needsConsent())->toBeTrue()
        ->and($procedure->events()->where('type', 'sent_for_consent')->exists())->toBeTrue();

    Mail::assertSent(DocumentNoticeMail::class, fn ($mail) => $mail->hasTo('toata-lumea@christiantour.ro'));
});

test('a procedure without a file is not sent anywhere', function () {
    $empty = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_PROCEDURE, 'number' => 'PRO-2026-0002', 'title' => 'Goală',
    ]);

    $this->actingAs($this->keeper)
        ->post("/documente/proceduri/{$empty->id}/trimite")
        ->assertRedirect();

    expect($empty->fresh()->published_at)->toBeNull();
    Mail::assertNothingSent();
});

test('whoever holds the link reads the procedure and takes notice, without an account', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite");
    $token = $this->procedure->fresh()->token;

    // Fără cont: adresa de grup ajunge și la oameni care n-au cont aici.
    $this->get("/document/{$token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('documents/read')->where('needsConsent', true));

    $this->post("/document/{$token}/luare-la-cunostinta", [
        'name' => 'Andrei Popescu',
        'email' => 'Andrei.Popescu@christiantour.ro',
        'agreed' => true,
    ])->assertRedirect();

    $consent = DocumentConsent::query()->firstOrFail();

    expect($consent->name)->toBe('Andrei Popescu')
        // Adresa se ține cu litere mici: altfel același om ar putea semna de
        // două ori, scriind-o altfel.
        ->and($consent->email)->toBe('andrei.popescu@christiantour.ro')
        ->and($consent->consented_at)->not->toBeNull()
        ->and($consent->source)->toBe(DocumentConsent::SOURCE_LINK)
        ->and($consent->ip)->not->toBeNull()
        ->and($this->procedure->events()->where('type', 'consent')->exists())->toBeTrue();

    // Dovada îi pleacă pe chiar adresa scrisă.
    Mail::assertSent(ConsentReceiptMail::class, fn ($mail) => $mail->hasTo('andrei.popescu@christiantour.ro'));
});

test('the same address takes notice once, however many times it presses', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite");
    $token = $this->procedure->fresh()->token;

    $twice = fn () => $this->post("/document/{$token}/luare-la-cunostinta", [
        'name' => 'Andrei Popescu', 'email' => 'andrei.popescu@christiantour.ro', 'agreed' => true,
    ]);

    $twice()->assertRedirect();
    $twice()->assertRedirect();

    expect(DocumentConsent::query()->count())->toBe(1);
});

test('taking notice needs a work address and a ticked box', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite");
    $token = $this->procedure->fresh()->token;

    // Adresă personală: procedura internă nu se ia la cunoștință de pe ea.
    $this->post("/document/{$token}/luare-la-cunostinta", [
        'name' => 'Andrei Popescu', 'email' => 'andrei@gmail.com', 'agreed' => true,
    ])->assertSessionHasErrors('email');

    // Nebifat: n-a declarat nimeni nimic.
    $this->post("/document/{$token}/luare-la-cunostinta", [
        'name' => 'Andrei Popescu', 'email' => 'andrei.popescu@christiantour.ro', 'agreed' => false,
    ])->assertSessionHasErrors('agreed');

    expect(DocumentConsent::query()->count())->toBe(0);
});

test('a colleague with an account is recognised, and the register knows he has read it', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite");
    $token = $this->procedure->fresh()->token;

    $this->actingAs($this->colleague)
        ->post("/document/{$token}/luare-la-cunostinta", [
            'name' => $this->colleague->name, 'email' => $this->colleague->email, 'agreed' => true,
        ])
        ->assertRedirect();

    $consent = DocumentConsent::query()->firstOrFail();

    expect($consent->source)->toBe(DocumentConsent::SOURCE_ACCOUNT)
        ->and($consent->user_id)->toBe($this->colleague->id);

    // Lista îi spune că el a confirmat deja; altuia îi spune că n-a confirmat.
    $this->actingAs($this->colleague)
        ->get('/documente/proceduri')
        ->assertInertia(fn ($page) => $page->where('documents.data.0.i_consented', true));

    $this->actingAs($this->keeper)
        ->get('/documente/proceduri')
        ->assertInertia(fn ($page) => $page
            ->where('documents.data.0.i_consented', false)
            ->where('documents.data.0.consents_count', 1));
});

test('the company mail group is the procedures address, never the decisions one', function () {
    $decision = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_DECISION, 'number' => 'DEC-2026-0002', 'title' => 'Decizie internă',
        'status' => CompanyDocument::STATUS_ACTIVE,
    ]);
    CompanyDocumentFile::query()->create([
        'company_document_id' => $decision->id, 'path' => 'd2.txt', 'original_name' => 'd2.txt', 'hash' => 'h9', 'text' => 'Se decide.',
    ]);

    // Fără adrese scrise anume, decizia nu pleacă nicăieri: nu cade pe adresa
    // de grup, pe care stă toată compania.
    $this->actingAs($this->keeper)
        ->post("/documente/decizii/{$decision->id}/trimite")
        ->assertRedirect();

    expect($decision->fresh()->published_at)->toBeNull();
    Mail::assertNothingSent();

    // Nici ecranul nu i-o propune: câmpul vine gol la decizii, plin la proceduri.
    $this->actingAs($this->keeper)
        ->get("/documente/decizii/{$decision->id}")
        ->assertInertia(fn ($page) => $page->where('consentGroup', ''));

    $this->actingAs($this->keeper)
        ->get("/documente/proceduri/{$this->procedure->id}")
        ->assertInertia(fn ($page) => $page->where('consentGroup', 'toata-lumea@christiantour.ro'));

    $this->actingAs($this->keeper)
        ->get('/documente/decizii')
        ->assertInertia(fn ($page) => $page->where('consentGroup', ''));
});

test('a procedure goes to every address of the group, each with its own mail', function () {
    config(['documents.consent.group' => 'central@christiantour.ro,intern@christiantour.ro']);

    $this->actingAs($this->keeper)
        ->post("/documente/proceduri/{$this->procedure->id}/trimite")
        ->assertRedirect();

    Mail::assertSent(DocumentNoticeMail::class, 2);
    Mail::assertSent(DocumentNoticeMail::class, fn ($mail) => $mail->hasTo('central@christiantour.ro'));
    Mail::assertSent(DocumentNoticeMail::class, fn ($mail) => $mail->hasTo('intern@christiantour.ro'));

    expect($this->procedure->fresh()->sent_to)->toBe('central@christiantour.ro, intern@christiantour.ro');
});

test('a decision is communicated, but nobody is asked to take notice of it', function () {
    $decision = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_DECISION, 'number' => 'DEC-2026-0001', 'title' => 'Decizie',
        'status' => CompanyDocument::STATUS_ACTIVE,
    ]);
    CompanyDocumentFile::query()->create([
        'company_document_id' => $decision->id, 'path' => 'd.txt', 'original_name' => 'd.txt', 'hash' => 'h2', 'text' => 'Se decide.',
    ]);

    $this->actingAs($this->keeper)
        ->post("/documente/decizii/{$decision->id}/trimite", ['emails' => 'cineva@christiantour.ro'])
        ->assertRedirect();

    $decision->refresh();

    expect($decision->published_at)->not->toBeNull()
        ->and($decision->consent_due_at)->toBeNull()
        ->and($decision->needsConsent())->toBeFalse();

    // Legătura o deschide, dar n-are ce confirma nimeni pe ea.
    $this->get("/document/{$decision->token}")->assertOk();
    $this->post("/document/{$decision->token}/luare-la-cunostinta", [
        'name' => 'Andrei Popescu', 'email' => 'andrei.popescu@christiantour.ro', 'agreed' => true,
    ])->assertNotFound();
});

test('a link to a procedure that was never sent opens nothing', function () {
    $this->get('/document/'.str_repeat('a', 48))->assertNotFound();

    // Nici cea pusă în arhivă după ce fusese trimisă.
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite");
    $token = $this->procedure->fresh()->token;
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/arhiveaza");

    $this->get("/document/{$token}")->assertNotFound();
});

test('the proof comes out as a spreadsheet, for whoever audits it', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite");
    $token = $this->procedure->fresh()->token;

    $this->post("/document/{$token}/luare-la-cunostinta", [
        'name' => 'Andrei Popescu', 'email' => 'andrei.popescu@christiantour.ro', 'agreed' => true,
    ]);

    $response = $this->actingAs($this->keeper)
        ->get("/documente/proceduri/{$this->procedure->id}/luari-la-cunostinta")
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('spreadsheetml');
});
