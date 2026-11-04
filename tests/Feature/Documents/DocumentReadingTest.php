<?php

use App\Jobs\ReadDocumentFile;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentFile;
use App\Models\User;
use App\Services\Contracts\ContractReader;
use App\Services\Documents\DocumentFields;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

/** O decizie ca în viață: antet, număr, dată, „privind…”, semnătură. */
function decisionText(): string
{
    return <<<'TEXT'
        S.C. CHRISTIAN TOUR S.R.L.
        CUI RO12345678, J40/1234/2001

        DECIZIA nr. 147 din 12.02.2026
        privind aprobarea programului de lucru în perioada sărbătorilor de iarnă

        Administratorul societății, având în vedere prevederile Regulamentului Intern,

        DECIDE:

        Art. 1 — Programul de lucru intră în vigoare la data de 01.03.2026.
        Art. 2 — Prezenta decizie se comunică tuturor departamentelor.

        Administrator,
        Cristian Pandel
        TEXT;
}

/** O procedură ca în viață: cod, ediție, scop. */
function procedureText(): string
{
    return <<<'TEXT'
        PROCEDURA OPERAȚIONALĂ nr. PO-FIN-05
        Ediția 2, Revizia 1, din 03.09.2026

        Scopul prezentei proceduri este de a stabili modul în care se decontează cheltuielile
        de deplasare ale salariaților.

        Procedura se aplică începând cu 01.10.2026.

        Aprobat de: Director Financiar
        TEXT;
}

test('the reader pulls out of a decision what a person would write down anyway', function () {
    $fields = app(DocumentFields::class)->extract(decisionText(), CompanyDocument::TYPE_DECISION);

    expect($fields['number']['value'])->toBe('147')
        ->and($fields['issued_at']['value'])->toBe('2026-02-12')
        ->and($fields['effective_at']['value'])->toBe('2026-03-01')
        // Antetul firmei nu e titlu: titlul e rândul care spune ce e hârtia.
        ->and($fields['title']['value'])->toContain('DECIZIA nr. 147')
        ->and($fields['object']['value'])->toContain('programului de lucru')
        ->and($fields['issuer']['value'])->toBe('Administrator')
        ->and($fields['issued_at']['confidence'])->toBeGreaterThan(0.85)
        ->and($fields['object']['source'])->not->toBeEmpty();
});

test('a procedure gives up its code, its date and the day it starts applying', function () {
    $fields = app(DocumentFields::class)->extract(procedureText(), CompanyDocument::TYPE_PROCEDURE);

    expect($fields['number']['value'])->toBe('PO-FIN-05')
        ->and($fields['issued_at']['value'])->toBe('2026-09-03')
        ->and($fields['effective_at']['value'])->toBe('2026-10-01')
        ->and($fields['object']['value'])->toContain('se decontează cheltuielile')
        ->and($fields['issuer']['value'])->toBe('Director Financiar');
});

test('the signature block names the issuer, not whoever approves a form inside the text', function () {
    // Pățania unei proceduri adevărate: în cuprins scrie „formularul din Anexa 1,
    // aprobat de șeful de departament”, iar la coadă „Aprobat de: Director
    // Financiar”. Emitentul e cel de la semnătură.
    $text = <<<'TEXT'
        PROCEDURA OPERAȚIONALĂ nr. PO-FIN-07
        din 04.11.2026

        3. Solicitarea deplasării
        Deplasarea se solicită prin formularul din Anexa 1, aprobat de șeful de departament.

        6. Răspunderi
        Șeful de departament răspunde de oportunitatea deplasării.

        Aprobat de: Director Financiar
        Elaborat de: Departamentul Financiar
        TEXT;

    $fields = app(DocumentFields::class)->extract($text, CompanyDocument::TYPE_PROCEDURE);

    expect($fields['issuer']['value'])->toBe('Director Financiar');
});

test('with no approval box, the issuer is the last role on the page — the one who signs', function () {
    $text = <<<'TEXT'
        DECIZIA nr. 3 din 04.11.2026
        privind ceva

        Directorul General al societății, având în vedere referatul Directorului Economic,
        DECIDE:

        Art. 1 — Ceva.

        Administrator,
        Cristian Pandel
        TEXT;

    $fields = app(DocumentFields::class)->extract($text, CompanyDocument::TYPE_DECISION);

    expect($fields['issuer']['value'])->toBe('Administrator');
});

test('reading a file fills the document, without touching what a person wrote', function () {
    Storage::fake(config('documents.disk'));

    $this->mock(ContractReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('read')->andReturn([
            'engine' => 'pdftotext', 'text' => decisionText(), 'pages' => 2, 'status' => 'done', 'error' => null,
        ]);
    });

    $document = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_DECISION,
        'number' => 'DEC-2026-0001',
        // Titlul se naște din numele fișierului: mașina îl poate înlocui.
        'title' => 'decizie_scanata_147',
        'ocr_fields' => ['title' => ['value' => 'decizie_scanata_147', 'confidence' => 0.1, 'source' => 'numele fișierului']],
        // Emitentul a fost pus de om: rămâne al lui.
        'issuer' => 'Consiliul de Administrație',
    ]);
    $file = CompanyDocumentFile::query()->create([
        'company_document_id' => $document->id, 'path' => 'documents/1/x.pdf', 'original_name' => 'x.pdf', 'hash' => 'abc',
    ]);
    Storage::disk(config('documents.disk'))->put('documents/1/x.pdf', 'oricum e citit de mock');

    app(ReadDocumentFile::class, ['fileId' => $file->id])->handle(app(ContractReader::class), app(DocumentFields::class));

    $document->refresh();

    expect($document->title)->toContain('DECIZIA nr. 147')
        ->and($document->issued_at?->toDateString())->toBe('2026-02-12')
        ->and($document->effective_at?->toDateString())->toBe('2026-03-01')
        ->and($document->object)->toContain('programului de lucru')
        // Ce a scris omul rămâne scris de om.
        ->and($document->issuer)->toBe('Consiliul de Administrație')
        // Numărul din registru e al nostru: citirea îl propune, nu îl schimbă.
        ->and($document->number)->toBe('DEC-2026-0001')
        ->and($document->ocr_fields['number']['value'])->toBe('147')
        ->and($file->fresh()->ocr_status)->toBe(CompanyDocumentFile::OCR_DONE)
        ->and($file->fresh()->text)->toContain('DECIDE')
        ->and($document->events()->where('type', 'ocr_done')->exists())->toBeTrue();
});

test('a file that cannot be read says so instead of inventing', function () {
    Storage::fake(config('documents.disk'));

    $this->mock(ContractReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('read')->andReturn([
            'engine' => 'tesseract', 'text' => '', 'pages' => 1, 'status' => 'failed', 'error' => 'scanare prea slabă',
        ]);
    });

    $document = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_PROCEDURE, 'number' => 'PRO-2026-0001', 'title' => 'scan',
    ]);
    $file = CompanyDocumentFile::query()->create([
        'company_document_id' => $document->id, 'path' => 'documents/2/x.pdf', 'original_name' => 'x.pdf', 'hash' => 'def',
    ]);
    Storage::disk(config('documents.disk'))->put('documents/2/x.pdf', 'nimic bun');

    app(ReadDocumentFile::class, ['fileId' => $file->id])->handle(app(ContractReader::class), app(DocumentFields::class));

    expect($file->fresh()->ocr_status)->toBe(CompanyDocumentFile::OCR_FAILED)
        ->and($file->fresh()->ocr_error)->toBe('scanare prea slabă')
        ->and($document->fresh()->title)->toBe('scan')
        ->and($document->events()->where('type', 'ocr_failed')->exists())->toBeTrue();
});

test('a pointed question is answered with the lines that say it', function () {
    // Fără agent: răspunsul e chiar textul documentului, iar proba nu cheltuie
    // nimic la furnizorul de model.
    config(['documents.ai.enabled' => false]);

    $document = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_PROCEDURE, 'number' => 'PRO-2026-0002', 'title' => 'Decontare',
    ]);
    CompanyDocumentFile::query()->create([
        'company_document_id' => $document->id, 'path' => 'p.txt', 'original_name' => 'p.txt', 'hash' => 'ghi',
        'text' => procedureText()."\n\nDecontul se depune la Financiar în cel mult cinci zile lucrătoare de la întoarcerea din deplasare, împreună cu documentele justificative.",
    ]);

    $keeper = User::factory()->withRoles('contract_management')->create();

    $this->actingAs($keeper)
        ->postJson("/documente/proceduri/{$document->id}/intreaba", ['question' => 'în cât timp se depune decontul?'])
        ->assertOk()
        ->assertJsonPath('searched', true)
        ->assertJsonFragment(['by' => 'search']);
});
