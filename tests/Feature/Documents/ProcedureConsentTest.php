<?php

use App\Mail\ConsentReceiptMail;
use App\Mail\DocumentNoticeMail;
use App\Mail\DocumentReminderMail;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentFile;
use App\Models\DocumentConsent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
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

    // Subiectul spune din căsuță despre ce e vorba și ce se cere.
    expect((new DocumentNoticeMail($procedure))->envelope()->subject)
        ->toBe('Procedură nouă — PRO-2026-0001 · Procedura de decontare a cheltuielilor de deplasare: de citit și de luat la cunoștință');
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
    Mail::assertQueued(ConsentReceiptMail::class, fn ($mail) => $mail->hasTo('andrei.popescu@christiantour.ro'));
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

test('a decision goes to the same company mail groups as a procedure', function () {
    config(['documents.consent.group' => 'central@christiantour.ro,intern@christiantour.ro']);

    $decision = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_DECISION, 'number' => 'DEC-2026-0002', 'title' => 'Decizie internă',
        'status' => CompanyDocument::STATUS_ACTIVE,
    ]);
    CompanyDocumentFile::query()->create([
        'company_document_id' => $decision->id, 'path' => 'd2.txt', 'original_name' => 'd2.txt', 'hash' => 'h9', 'text' => 'Se decide.',
    ]);

    // Fără adrese scrise anume, decizia pleacă tot pe adresele de grup: pe ele
    // stă toată compania, și deciziile o privesc la fel ca procedurile.
    $this->actingAs($this->keeper)
        ->post("/documente/decizii/{$decision->id}/trimite")
        ->assertRedirect();

    Mail::assertSent(DocumentNoticeMail::class, 2);
    Mail::assertSent(DocumentNoticeMail::class, fn ($mail) => $mail->hasTo('central@christiantour.ro'));
    Mail::assertSent(DocumentNoticeMail::class, fn ($mail) => $mail->hasTo('intern@christiantour.ro'));

    $decision->refresh();

    expect($decision->sent_to)->toBe('central@christiantour.ro, intern@christiantour.ro')
        ->and($decision->published_at)->not->toBeNull()
        // Și cere la fel luarea la cunoștință, cu termenul din configurare.
        ->and($decision->consent_due_at?->toDateString())->toBe(now()->addDays(14)->toDateString())
        ->and($decision->needsConsent())->toBeTrue();

    // Iar ecranul le propune la amândouă, nu doar la proceduri.
    foreach (['decizii', 'proceduri'] as $slug) {
        $this->actingAs($this->keeper)
            ->get("/documente/{$slug}")
            ->assertInertia(fn ($page) => $page->where('consentGroup', 'central@christiantour.ro,intern@christiantour.ro'));
    }

    $this->actingAs($this->keeper)
        ->get("/documente/decizii/{$decision->id}")
        ->assertInertia(fn ($page) => $page->where('consentGroup', 'central@christiantour.ro,intern@christiantour.ro'));
});

test('without an address nothing leaves, whichever the paper', function () {
    config(['documents.consent.group' => '']);

    $this->actingAs($this->keeper)
        ->post("/documente/proceduri/{$this->procedure->id}/trimite")
        ->assertRedirect();

    expect($this->procedure->fresh()->published_at)->toBeNull();
    Mail::assertNothingSent();
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

test('a decision is taken notice of exactly like a procedure', function () {
    $decision = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_DECISION, 'number' => 'DEC-2026-0001', 'title' => 'Decizie',
        'status' => CompanyDocument::STATUS_ACTIVE,
    ]);
    CompanyDocumentFile::query()->create([
        'company_document_id' => $decision->id, 'path' => 'd.txt', 'original_name' => 'd.txt', 'hash' => 'h2', 'text' => 'Se decide.',
    ]);

    $this->actingAs($this->keeper)
        ->post("/documente/decizii/{$decision->id}/trimite", ['emails' => 'cineva@christiantour.ro', 'due' => '2026-12-01'])
        ->assertRedirect();

    $decision->refresh();

    expect($decision->published_at)->not->toBeNull()
        ->and($decision->consent_due_at?->toDateString())->toBe('2026-12-01')
        ->and($decision->needsConsent())->toBeTrue();

    // Mailul spune din subiect ce fel de hârtie e și ce are omul de făcut.
    Mail::assertSent(DocumentNoticeMail::class, fn ($mail) => $mail->envelope()->subject === 'Decizie nouă — DEC-2026-0001 · Decizie: de citit și de luat la cunoștință');

    // Iar cel fără cont o citește și apasă, pe aceeași pagină ca la proceduri.
    $this->get("/document/{$decision->token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('needsConsent', true));

    $this->post("/document/{$decision->token}/luare-la-cunostinta", [
        'name' => 'Andrei Popescu', 'email' => 'andrei.popescu@christiantour.ro', 'agreed' => true,
    ])->assertRedirect();

    $consent = DocumentConsent::query()->where('company_document_id', $decision->id)->sole();

    expect($consent->name)->toBe('Andrei Popescu')
        ->and($consent->source)->toBe(DocumentConsent::SOURCE_LINK);

    Mail::assertQueued(ConsentReceiptMail::class);

    // Și dovada iese în foaia de calcul, din registrul deciziilor.
    $response = $this->actingAs($this->keeper)
        ->get("/documente/decizii/{$decision->id}/luari-la-cunostinta")
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('spreadsheetml');
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

test('a page left open too long sends the reader back to it, not to an error', function () {
    // Jetonul de formular moare odată cu sesiunea, iar procedura se citește pe
    // îndelete, dintr-un mail: cine apasă după trei ore trebuie să se
    // trezească tot la documentul lui, cu un rând care spune ce s-a întâmplat.
    Route::get('/_jeton-expirat', fn () => abort(419))->middleware('web');

    $page = url('/document/'.str_repeat('a', 48));

    $this->get('/_jeton-expirat', ['referer' => $page])->assertRedirect($page);

    // Dintr-un loc străin nu-l trimitem înapoi acolo.
    $this->get('/_jeton-expirat', ['referer' => 'https://alt-site.example/pagina'])
        ->assertRedirect(url('/'));
});

test('the mail is signed by the house, not by the application', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite");

    // Cine primește procedura vede în căsuță un nume de om, nu numele unei
    // aplicații de facturi despre care n-a auzit.
    Mail::assertSent(DocumentNoticeMail::class, fn ($mail) => $mail->envelope()->from?->name === 'Administrator Christian Tour');
});

test('uploading a paper tells nobody: the sending is done by hand', function () {
    // Încărcarea doar pune hârtia în registru și o dă la citit. Dacă ar pleca
    // singură, o ciornă greșit urcată ar ajunge în toată compania.
    $this->actingAs($this->keeper)
        ->post('/documente/proceduri', ['files' => [UploadedFile::fake()->create('Procedura noua.pdf', 40, 'application/pdf')]])
        ->assertRedirect();

    $fresh = CompanyDocument::query()->ofType(CompanyDocument::TYPE_PROCEDURE)->latest('id')->firstOrFail();

    // N-a plecat nicăieri: n-are nici ceasul trimiterii, nici legătura pe
    // care s-ar deschide din afară, deci nici n-are cine s-o ia la cunoștință.
    expect($fresh->published_at)->toBeNull()
        ->and($fresh->token)->toBeNull()
        ->and($fresh->sent_to)->toBeNull()
        ->and($fresh->needsConsent())->toBeFalse();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    // Nici versiunea pusă peste o procedură deja trimisă nu dă de veste din
    // nou: mailul pleacă o dată, când apeși „Trimite”.
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite");

    Mail::assertSent(DocumentNoticeMail::class, 1);

    $this->actingAs($this->keeper)
        ->post("/documente/proceduri/{$this->procedure->id}/fisiere", [
            'files' => [UploadedFile::fake()->create('Anexa 2.pdf', 10, 'application/pdf')],
            'kind' => 'annex',
        ])
        ->assertRedirect();

    Mail::assertSent(DocumentNoticeMail::class, 1);
});

test('the same man, written with two addresses, is marked for checking', function () {
    // Adresa nu se poate repeta — dar scrisă altfel trece, și atunci omul e
    // numărat de două ori. Nu refuzăm nimic: registrul își pune semnul, ca
    // numărul să nu fie luat de bun fără să se uite cineva la el.
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite");
    $token = $this->procedure->fresh()->token;

    $this->post("/document/{$token}/luare-la-cunostinta", [
        'name' => 'Ion Popescu', 'email' => 'ion.popescu@christiantour.ro', 'agreed' => true,
    ])->assertRedirect();

    $this->post("/document/{$token}/luare-la-cunostinta", [
        'name' => 'POPESCU ION', 'email' => 'i.popescu@christiantour.ro', 'agreed' => true,
    ])->assertRedirect();

    expect(DocumentConsent::query()->count())->toBe(2);

    $this->actingAs($this->keeper)
        ->get("/documente/proceduri/{$this->procedure->id}")
        ->assertInertia(fn ($page) => $page
            // Registrul vine cu cea mai nouă confirmare prima.
            ->where('document.consents.0.twin', 'ion.popescu@christiantour.ro')
            ->where('document.consents.1.twin', 'i.popescu@christiantour.ro')
            // Niciuna din cele două adrese n-are cont: și asta se vede.
            ->where('document.consents.0.matched', false));
});

test('an address with an account is not marked, and the name is tidied up before comparing', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite");
    $token = $this->procedure->fresh()->token;

    $this->post("/document/{$token}/luare-la-cunostinta", [
        'name' => $this->colleague->name, 'email' => $this->colleague->email, 'agreed' => true,
    ])->assertRedirect();

    $this->actingAs($this->keeper)
        ->get("/documente/proceduri/{$this->procedure->id}")
        ->assertInertia(fn ($page) => $page
            ->where('document.consents.0.matched', true)
            ->where('document.consents.0.twin', null));

    // Diacriticele, ordinea cuvintelor și literele mari nu fac alt om.
    expect(DocumentConsent::samePerson('Ștefan-Andrei Pîrvu'))
        ->toBe(DocumentConsent::samePerson('pirvu stefan andrei'))
        ->and(DocumentConsent::samePerson('Ion Popescu'))
        ->not->toBe(DocumentConsent::samePerson('Ioana Popescu'));
});

test('the consent belongs to the address written, not to whoever is logged in', function () {
    // Cel cu cont poate deschide legătura lângă un coleg fără cont, de pe
    // calculatorul lui. Dovada e a colegului: altfel registrul ar arăta că
    // una și aceeași persoană a luat la cunoștință pentru toată lumea.
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite");
    $token = $this->procedure->fresh()->token;

    $this->actingAs($this->keeper)
        ->post("/document/{$token}/luare-la-cunostinta", [
            'name' => $this->colleague->name, 'email' => $this->colleague->email, 'agreed' => true,
        ])->assertRedirect();

    $consent = DocumentConsent::query()->firstOrFail();

    expect($consent->user_id)->toBe($this->colleague->id)
        ->and($consent->source)->toBe(DocumentConsent::SOURCE_LINK);

    // Iar o adresă care nu e a nimănui din aplicație rămâne nelegată.
    $this->post("/document/{$token}/luare-la-cunostinta", [
        'name' => 'Mihai Dobre', 'email' => 'mihai.dobre@christiantour.ro', 'agreed' => true,
    ])->assertRedirect();

    expect(DocumentConsent::query()->where('email', 'mihai.dobre@christiantour.ro')->value('user_id'))->toBeNull();
});

test('with a day left, the paper reminds the company by itself', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite", [
        'emails' => 'central@christiantour.ro, intern@christiantour.ro',
        'due' => now()->addDay()->toDateString(),
    ]);

    $this->artisan('documents:remind')->assertSuccessful();

    // Pe aceleași adrese pe care a plecat hârtia, fiindcă altele n-avem.
    Mail::assertSent(DocumentReminderMail::class, 2);
    Mail::assertSent(DocumentReminderMail::class, fn ($mail) => $mail->hasTo('central@christiantour.ro'));
    Mail::assertSent(DocumentReminderMail::class, fn ($mail) => $mail->hasTo('intern@christiantour.ro'));

    $procedure = $this->procedure->fresh();

    expect($procedure->consent_reminded_at)->not->toBeNull()
        ->and($procedure->events()->where('type', 'reminded')->exists())->toBeTrue();

    expect((new DocumentReminderMail($procedure))->envelope()->subject)
        ->toBe('Mâine e ultima zi — Procedură PRO-2026-0001 · Procedura de decontare a cheltuielilor de deplasare: de luat la cunoștință');

    // Programul rulează în fiecare zi: a doua trecere nu mai trimite nimic.
    $this->artisan('documents:remind')->assertSuccessful();
    Mail::assertSent(DocumentReminderMail::class, 2);
});

test('nothing is remembered for a deadline that is still far, or for a paper that never left', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite", [
        'due' => now()->addDays(6)->toDateString(),
    ]);

    $this->artisan('documents:remind')->assertSuccessful();

    Mail::assertNotSent(DocumentReminderMail::class);

    // Și o hârtie care n-a plecat în companie n-are cui să-i amintească.
    $draft = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_DECISION,
        'number' => 'DEC-2026-0009',
        'title' => 'Ciornă',
        'status' => CompanyDocument::STATUS_ACTIVE,
        'consent_due_at' => now()->addDay()->toDateString(),
    ]);

    $this->artisan('documents:remind')->assertSuccessful();
    Mail::assertNotSent(DocumentReminderMail::class);

    $this->actingAs($this->keeper)
        ->post("/documente/decizii/{$draft->id}/aminteste")
        ->assertRedirect();

    Mail::assertNotSent(DocumentReminderMail::class);
});

test('the button reminds whenever it is pressed, and does not block the one before the deadline', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite", [
        'emails' => 'central@christiantour.ro',
        'due' => now()->addDays(10)->toDateString(),
    ]);

    $this->actingAs($this->keeper)
        ->post("/documente/proceduri/{$this->procedure->id}/aminteste")
        ->assertRedirect();

    Mail::assertSent(DocumentReminderMail::class, 1);
    expect($this->procedure->fresh()->consent_reminded_at)->not->toBeNull();

    // Peste câteva zile, cu o zi înainte de termen, programul își face
    // treaba oricum: aducerea aminte apăsată cu mâna săptămâna trecută nu-l
    // oprește.
    $this->procedure->fresh()->forceFill([
        'consent_due_at' => now()->addDay()->toDateString(),
        'consent_reminded_at' => now()->subDays(7),
    ])->save();

    $this->artisan('documents:remind')->assertSuccessful();

    Mail::assertSent(DocumentReminderMail::class, 2);
});

test('the reminder says how much time is left, whichever day it is pressed', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite", [
        'due' => now()->addDay()->toDateString(),
    ]);

    $tomorrow = new DocumentReminderMail($this->procedure->fresh());

    expect($tomorrow->days())->toBe(1)
        ->and($tomorrow->urgency()['badge'])->toStartWith('Mâine, ');

    $this->procedure->fresh()->forceFill(['consent_due_at' => now()->toDateString()])->save();
    expect((new DocumentReminderMail($this->procedure->fresh()))->urgency()['badge'])->toStartWith('Azi, ');

    $this->procedure->fresh()->forceFill(['consent_due_at' => now()->subDays(2)->toDateString()])->save();
    expect((new DocumentReminderMail($this->procedure->fresh()))->urgency()['badge'])->toStartWith('Termenul a trecut pe ');

    $this->procedure->fresh()->forceFill(['consent_due_at' => now()->addDays(4)->toDateString()])->save();
    expect((new DocumentReminderMail($this->procedure->fresh()))->urgency()['badge'])->toBe('Mai sunt 4 zile: termenul e '.now()->addDays(4)->format('d.m.Y').'.');
});

/*
 * Raftul: de la o hârtie primită pe mail, toate celelalte.
 *
 * Conturile sunt vreo treizeci, hârtiile sunt ale întregii companii. Cine
 * n-are cont își ține deciziile și procedurile doar în căsuța de mail, așa
 * că legătura uneia îi deschide raftul cu toate — de citit, atât.
 */
test('the link of one paper opens the shelf with all of them', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite", ['due' => '2026-11-20']);
    $token = $this->procedure->fresh()->token;

    $decision = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_DECISION,
        'number' => 'DEC-2026-0007',
        'title' => 'Decizia privind programul de lucru',
        'status' => CompanyDocument::STATUS_ACTIVE,
        'issued_at' => '2026-09-01',
        'effective_at' => '2026-09-15',
        'published_at' => now(),
        'token' => str_repeat('d', 48),
    ]);

    // Ciorna n-a plecat la nimeni, iar cea arhivată a ieșit din uz: niciuna
    // n-are ce căuta pe raft.
    CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_DECISION, 'number' => 'DEC-2026-0008', 'title' => 'Ciornă',
    ]);
    CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_DECISION, 'number' => 'DEC-2025-0001', 'title' => 'Scoasă din uz',
        'published_at' => now()->subYear(), 'archived_at' => now(), 'token' => str_repeat('a', 48),
    ]);

    $this->get("/document/{$token}/toate")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('documents/library')
            ->where('opened.number', 'PRO-2026-0001')
            ->has('papers', 2)
            ->where('papers.0.number', $decision->number)
            ->where('papers.0.url', route('documents.read', $decision->token))
            ->where('papers.1.number', 'PRO-2026-0001'),
        );
});

test('the shelf opens only with a link that is still good', function () {
    $this->get('/document/'.str_repeat('x', 48).'/toate')->assertNotFound();
});

test('the shelf shows the reader what is already taken note of', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite", ['due' => '2026-11-20']);
    $token = $this->procedure->fresh()->token;

    $this->post("/document/{$token}/luare-la-cunostinta", [
        'name' => 'Ioana Vlad', 'email' => $this->colleague->email, 'agreed' => true,
    ])->assertRedirect();

    $this->actingAs($this->colleague)
        ->get("/document/{$token}/toate")
        ->assertInertia(fn ($page) => $page->where('papers.0.mine', true));

    // Altcineva n-a apăsat încă: raftul n-o spune despre el.
    $this->actingAs($this->keeper)
        ->get("/document/{$token}/toate")
        ->assertInertia(fn ($page) => $page->where('papers.0.mine', false));
});

test('the shelf can be shut from the settings', function () {
    $this->actingAs($this->keeper)->post("/documente/proceduri/{$this->procedure->id}/trimite", ['due' => '2026-11-20']);
    $token = $this->procedure->fresh()->token;

    config(['documents.library.open' => false]);

    $this->get("/document/{$token}/toate")->assertNotFound();
    // Hârtia primită se citește oricum: raftul e în plus, nu în loc.
    $this->get("/document/{$token}")->assertOk();
});
