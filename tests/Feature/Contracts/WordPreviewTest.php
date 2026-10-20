<?php

use App\Models\Contract;
use App\Models\ContractFile;
use App\Models\Department;
use App\Models\User;
use App\Services\Contracts\WordPreview;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('contracts.disk'));

    $this->keeper = User::factory()->withRoles('contract_management')->create();
    $this->keeper->departments()->sync([Department::query()->whereNotNull('code')->value('id')]);
});

/** Un .docx adevărat, făcut pe loc: un titlu, un paragraf îngroșat și un tabel. */
function wordFile(string $path): void
{
    $document = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
      <w:body>
        <w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>CONTRACT DE PRESTĂRI</w:t></w:r></w:p>
        <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Părțile</w:t></w:r><w:r><w:t> au convenit.</w:t></w:r></w:p>
        <w:tbl>
          <w:tr><w:tc><w:p><w:r><w:t>Serviciu</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>700 EUR</w:t></w:r></w:p></w:tc></w:tr>
        </w:tbl>
      </w:body>
    </w:document>
    XML;

    $full = Storage::disk(config('contracts.disk'))->path($path);
    @mkdir(dirname($full), 0775, true);

    $zip = new ZipArchive;
    $zip->open($full, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', $document);
    $zip->close();
}

test('a Word document is shown as a page, not as a heap of text', function () {
    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0400', 'title' => 'Prestări', 'partner_name' => 'Alfa SRL',
        'created_by_id' => $this->keeper->id,
    ]);

    wordFile('w.docx');

    $file = ContractFile::query()->create([
        'contract_id' => $contract->id, 'kind' => ContractFile::KIND_CONTRACT, 'version' => 1,
        'path' => 'w.docx', 'original_name' => 'contract.docx', 'hash' => 'w1',
        'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);

    $html = app(WordPreview::class)->html($file);

    expect($html)->toContain('CONTRACT DE PRESTĂRI')
        // Titlul e titlu, nu un rând oarecare.
        ->and($html)->toContain('<h2>CONTRACT DE PRESTĂRI</h2>')
        ->and($html)->toContain('<strong>Părțile</strong>')
        // Tabelul rămâne tabel.
        ->and($html)->toContain('<table>')
        ->and($html)->toContain('700 EUR');

    // Și se deschide în fereastră, ca pagină.
    $this->actingAs($this->keeper)
        ->get("/contracts/{$contract->id}/files/{$file->id}/preview")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8');
});

test('a document from another contract is not shown', function () {
    $mine = Contract::query()->create(['number' => 'CTR-2026-0401', 'title' => 'A', 'partner_name' => 'x', 'created_by_id' => $this->keeper->id]);
    $his = Contract::query()->create(['number' => 'CTR-2026-0402', 'title' => 'B', 'partner_name' => 'y', 'created_by_id' => $this->keeper->id]);

    wordFile('z.docx');

    $file = ContractFile::query()->create([
        'contract_id' => $his->id, 'kind' => ContractFile::KIND_CONTRACT, 'version' => 1,
        'path' => 'z.docx', 'original_name' => 'altul.docx', 'hash' => 'w2',
    ]);

    $this->actingAs($this->keeper)
        ->get("/contracts/{$mine->id}/files/{$file->id}/preview")
        ->assertForbidden();
});

test('a Word file with nothing readable says so instead of showing a blank page', function () {
    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0403', 'title' => 'Gol', 'partner_name' => 'z', 'created_by_id' => $this->keeper->id,
    ]);

    $file = ContractFile::query()->create([
        'contract_id' => $contract->id, 'kind' => ContractFile::KIND_CONTRACT, 'version' => 1,
        'path' => 'lipsa.doc', 'original_name' => 'vechi.doc', 'hash' => 'w3', 'text' => null,
    ]);

    expect(app(WordPreview::class)->html($file))->toContain('nu s-a putut citi text');
});
