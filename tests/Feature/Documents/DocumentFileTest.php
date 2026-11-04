<?php

use App\Models\CompanyDocument;
use App\Models\CompanyDocumentFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake(config('documents.disk'));

    $this->keeper = User::factory()->withRoles('contract_management')->create();
    $this->colleague = User::factory()->withRoles('operational')->create();

    $this->procedure = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_PROCEDURE,
        'number' => 'PRO-2026-0001',
        'title' => 'Concediul de odihnă',
        'status' => CompanyDocument::STATUS_ACTIVE,
        'published_at' => now(),
        'token' => Str::random(48),
    ]);
});

/** Un fișier pe discul documentelor, cu rândul lui în registru. */
function documentFile(CompanyDocument $document, string $name, string $contents = '%PDF-1.4 proba'): CompanyDocumentFile
{
    Storage::disk(config('documents.disk'))->put('documents/'.$document->id.'/'.$name, $contents);

    return CompanyDocumentFile::query()->create([
        'company_document_id' => $document->id,
        'path' => 'documents/'.$document->id.'/'.$name,
        'original_name' => $name,
        'mime' => str_ends_with($name, '.pdf') ? 'application/pdf' : 'application/octet-stream',
        'size' => strlen($contents),
        'hash' => hash('sha256', $name),
    ]);
}

/** Un .docx adevărat, făcut pe loc, pe discul documentelor. */
function documentWordFile(CompanyDocument $document, string $name): CompanyDocumentFile
{
    $path = 'documents/'.$document->id.'/'.$name;
    $full = Storage::disk(config('documents.disk'))->path($path);
    @mkdir(dirname($full), 0775, true);

    $xml = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
      <w:body>
        <w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>PROCEDURA DE CONCEDIU</w:t></w:r></w:p>
        <w:p><w:r><w:t>Cererea se depune cu zece zile înainte.</w:t></w:r></w:p>
      </w:body>
    </w:document>
    XML;

    $zip = new ZipArchive;
    $zip->open($full, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();

    return CompanyDocumentFile::query()->create([
        'company_document_id' => $document->id,
        'path' => $path,
        'original_name' => $name,
        'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'size' => filesize($full),
        'hash' => hash('sha256', $name),
    ]);
}

test('the file opens in the page, for whoever reads the register', function () {
    $file = documentFile($this->procedure, 'procedura.pdf');

    $response = $this->actingAs($this->colleague)
        ->get("/documente/proceduri/{$this->procedure->id}/fisiere/{$file->id}/previzualizare")
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('inline');
});

test('the file downloads, and the journal remembers who took it', function () {
    $file = documentFile($this->procedure, 'procedura.pdf');

    $response = $this->actingAs($this->colleague)
        ->get("/documente/proceduri/{$this->procedure->id}/fisiere/{$file->id}")
        ->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('attachment')
        ->and($this->procedure->events()->where('type', 'downloaded')->exists())->toBeTrue();
});

test('whoever holds the link sees the document itself, not only its data', function () {
    // Pățania probei: pagina se deschidea, dar fereastra cu documentul dădea
    // 500, fiindcă fișierul plecase la cititorul de Word al contractelor.
    $file = documentFile($this->procedure, 'procedura.pdf');

    $response = $this->get("/document/{$this->procedure->token}/fisier?file={$file->id}")
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('inline');

    // Și descărcat, tot fără cont.
    $this->get("/document/{$this->procedure->token}/fisier?file={$file->id}&download=1")
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="procedura.pdf"');
});

test('a Word procedure is shown as a page, on its own disk', function () {
    $file = documentWordFile($this->procedure, 'procedura.docx');

    // Din aplicație.
    $this->actingAs($this->colleague)
        ->get("/documente/proceduri/{$this->procedure->id}/fisiere/{$file->id}/previzualizare")
        ->assertOk()
        ->assertSee('PROCEDURA DE CONCEDIU')
        ->assertSee('zece zile', false);

    // Și din legătura de mail, fără cont.
    $this->get("/document/{$this->procedure->token}/fisier?file={$file->id}")
        ->assertOk()
        ->assertSee('PROCEDURA DE CONCEDIU');

    // Descărcarea rămâne fișierul, nu pagina făcută din el.
    $this->get("/document/{$this->procedure->token}/fisier?file={$file->id}&download=1")
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="procedura.docx"');
});

test('a file of another document is refused', function () {
    $other = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_PROCEDURE, 'number' => 'PRO-2026-0002', 'title' => 'Alta',
    ]);
    $file = documentFile($other, 'alta.pdf');

    $this->actingAs($this->colleague)
        ->get("/documente/proceduri/{$this->procedure->id}/fisiere/{$file->id}")
        ->assertForbidden();
});

test('the link serves only the files of its own document', function () {
    $mine = documentFile($this->procedure, 'procedura.pdf');

    $other = CompanyDocument::query()->create([
        'type' => CompanyDocument::TYPE_PROCEDURE, 'number' => 'PRO-2026-0003', 'title' => 'Străina',
        'published_at' => now(), 'token' => Str::random(48),
    ]);
    $stranger = documentFile($other, 'straina.pdf');

    // Un număr de fișier străin nu scoate hârtia altuia pe legătura asta, dar
    // nici nu se preface că e a ei: se oprește.
    $this->get("/document/{$this->procedure->token}/fisier?file={$stranger->id}")->assertNotFound();

    // Fără număr, legătura dă chiar documentul ei.
    $this->get("/document/{$this->procedure->token}/fisier")
        ->assertOk()
        ->assertHeader('content-disposition', 'inline; filename="procedura.pdf"');

    expect($mine->id)->not->toBe($stranger->id);
});
