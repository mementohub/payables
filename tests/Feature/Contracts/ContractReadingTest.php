<?php

use App\Jobs\ReadContractFile;
use App\Mail\ContractAlertsMail;
use App\Mail\ContractSharedMail;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractFile;
use App\Models\Partner;
use App\Models\User;
use App\Services\Contracts\ContractFields;
use App\Services\Contracts\ContractReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

/** Un contract ca în viață: text curat, cu diacritice și cu cifre în el. */
function contractText(): string
{
    return <<<'TEXT'
        CONTRACT DE PRESTĂRI SERVICII DE TRANSPORT AERIAN
        nr. 147 / 12.02.2026

        Încheiat astăzi 12.02.2026 între:
        CHRISTIAN TOUR SRL, cu sediul în București, CUI RO12345678, în calitate de Beneficiar,
        și MEMENTO AIR SRL, cu sediul în Otopeni, C.U.I. RO38765432, în calitate de Prestator.

        Art. 1 — Obiectul contractului. Prestatorul se obligă să asigure capacitate garantată de
        transport aerian charter pe rutele București–Antalya și București–Hurghada. Grila de rotații
        este în Anexa 1.

        Art. 4 — Valoarea contractului este de 18.400.000 EUR, exclusiv TVA. Plata se face în 30 de
        zile de la data facturii.

        Art. 9 — Contractul produce efecte până la data de 31.10.2026, cu preaviz de 30 zile.

        Art. 14 — Prezentului contract i se aplică legea română.
        TEXT;
}

test('the reader pulls out of a contract what a person would write down anyway', function () {
    $fields = app(ContractFields::class)->extract(contractText(), ['Christian Tour']);

    expect($fields['partner_name']['value'])->toBe('MEMENTO AIR SRL')
        // Dintre cele două coduri fiscale din contract, al partenerului e cel
        // scris după numele lui, nu primul din antet.
        ->and($fields['partner_tax_id']['value'])->toBe('RO38765432')
        ->and($fields['signed_at']['value'])->toBe('2026-02-12')
        ->and($fields['expires_at']['value'])->toBe('2026-10-31')
        ->and($fields['notice_days']['value'])->toBe(30)
        ->and($fields['value']['value'])->toBe(['amount' => 18400000.0, 'currency' => 'EUR'])
        ->and($fields['payment_terms']['value'])->toBe('30 zile de la factură')
        ->and($fields['governing_law']['value'])->toBe('română')
        ->and($fields['object']['value'])->toContain('capacitate garantată')
        // Fiecare câmp vine cu gradul lui de încredere, între 0 și 1.
        ->and($fields['signed_at']['confidence'])->toBeGreaterThan(0.85)
        ->and($fields['object']['source'])->not->toBeEmpty();
});

test('a scanned text without diacritics is read just the same', function () {
    $flat = strtr(contractText(), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't', 'Î' => 'I', 'Ă' => 'A']);
    $fields = app(ContractFields::class)->extract($flat, ['Christian Tour']);

    expect($fields['signed_at']['value'])->toBe('2026-02-12')
        ->and($fields['expires_at']['value'])->toBe('2026-10-31')
        ->and($fields['value']['value']['amount'])->toBe(18400000.0);
});

test('our own company is never taken for the partner', function () {
    $fields = app(ContractFields::class)->extract(contractText(), ['Christian Tour']);

    expect($fields['partner_name']['value'])->not->toContain('CHRISTIAN');
});

test('what the machine is unsure about is named, so a person can look at it', function () {
    $fields = app(ContractFields::class)->extract(contractText(), ['Christian Tour']);
    $unsure = ContractFields::unsure($fields);

    expect($unsure)->toBeArray()
        // Datele și valoarea sunt sigure; obiectul și termenul de plată se verifică.
        ->and($unsure)->not->toContain('signed_at')
        ->and($unsure)->not->toContain('value');
});

test('reading a file fills the contract, without touching what a person wrote', function () {
    Storage::fake(config('contracts.disk'));
    Company::factory()->create(['name' => 'Christian Tour']);
    $partner = Partner::factory()->create(['name' => 'Memento Air Srl', 'cui' => 'RO38765432']);

    $this->mock(ContractReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('read')->andReturn([
            'engine' => 'pdftotext', 'text' => contractText(), 'pages' => 6, 'status' => 'done', 'error' => null,
        ]);
    });

    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0001',
        'title' => 'Contract Memento Air',
        'partner_name' => '—',
        // Omul a pus deja departamentul și o valoare a lui: nu se rescriu.
        'value' => 999.0,
        'currency' => 'RON',
    ]);
    $file = ContractFile::query()->create([
        'contract_id' => $contract->id, 'path' => 'contracts/1/x.pdf', 'original_name' => 'x.pdf', 'hash' => 'abc',
    ]);
    Storage::disk(config('contracts.disk'))->put('contracts/1/x.pdf', 'oricum e citit de mock');

    app(ReadContractFile::class, ['fileId' => $file->id])->handle(app(ContractReader::class), app(ContractFields::class));

    $contract->refresh();

    expect($contract->partner_name)->toBe('MEMENTO AIR SRL')
        ->and($contract->partner_id)->toBe($partner->id)
        ->and($contract->partner_tax_id)->toBe('RO38765432')
        ->and($contract->signed_at?->toDateString())->toBe('2026-02-12')
        ->and($contract->expires_at?->toDateString())->toBe('2026-10-31')
        ->and($contract->notice_days)->toBe(30)
        // Valoarea pusă de om rămâne a lui.
        ->and((float) $contract->value)->toBe(999.0)
        ->and($contract->currency)->toBe('RON')
        ->and((float) $contract->ocr_fields['value']['value']['amount'])->toBe(18400000.0)
        ->and($file->fresh()->ocr_status)->toBe(ContractFile::OCR_DONE)
        ->and($contract->events()->where('type', 'ocr_done')->exists())->toBeTrue();
});

test('a file that cannot be read says so instead of inventing', function () {
    Storage::fake(config('contracts.disk'));

    $this->mock(ContractReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('read')->andReturn([
            'engine' => 'niciunul', 'text' => '', 'pages' => null, 'status' => 'failed',
            'error' => 'serverul n-are încă tesseract',
        ]);
    });

    $contract = Contract::query()->create(['number' => 'CTR-2026-0002', 'title' => 'Scan', 'partner_name' => '—']);
    $file = ContractFile::query()->create([
        'contract_id' => $contract->id, 'path' => 'contracts/2/y.pdf', 'original_name' => 'y.pdf', 'hash' => 'def',
    ]);

    app(ReadContractFile::class, ['fileId' => $file->id])->handle(app(ContractReader::class), app(ContractFields::class));

    expect($file->fresh()->ocr_status)->toBe(ContractFile::OCR_FAILED)
        ->and($file->fresh()->ocr_error)->toContain('tesseract')
        ->and($contract->fresh()->partner_name)->toBe('—')
        ->and($contract->events()->where('type', 'ocr_failed')->exists())->toBeTrue();
});

test('the morning mail names the contracts that come due, and the notice that must be given', function () {
    Mail::fake();
    $keeper = User::factory()->withRoles('contract_management')->create();

    // Exact la 30 de zile: un prag din config.
    Contract::query()->create([
        'number' => 'CTR-A', 'title' => 'Charter', 'partner_name' => 'Memento Air',
        'status' => Contract::STATUS_ACTIVE, 'expires_at' => now()->addDays(30)->toDateString(),
    ]);
    // Preavizul e chiar azi.
    Contract::query()->create([
        'number' => 'CTR-B', 'title' => 'Combustibil', 'partner_name' => 'DKV',
        'status' => Contract::STATUS_ACTIVE, 'expires_at' => now()->addDays(45)->toDateString(), 'notice_days' => 45,
    ]);
    // Nici prag, nici preaviz: nu deranjează pe nimeni azi.
    Contract::query()->create([
        'number' => 'CTR-C', 'title' => 'Cazare', 'partner_name' => 'Planet Tours',
        'status' => Contract::STATUS_ACTIVE, 'expires_at' => now()->addDays(44)->toDateString(),
    ]);

    $this->artisan('contracts:alerts')->assertSuccessful();

    Mail::assertSent(ContractAlertsMail::class, function (ContractAlertsMail $mail) use ($keeper) {
        $numbers = array_column($mail->rows, 'number');

        return $mail->hasTo($keeper->email)
            && in_array('CTR-A', $numbers, true)
            && in_array('CTR-B', $numbers, true)
            && ! in_array('CTR-C', $numbers, true);
    });
});

test('the weekly digest carries everything on the horizon', function () {
    Mail::fake();
    User::factory()->withRoles('contract_management')->create();

    Contract::query()->create(['number' => 'CTR-A', 'title' => 'a', 'partner_name' => 'x', 'status' => Contract::STATUS_ACTIVE, 'expires_at' => now()->addDays(44)->toDateString()]);
    Contract::query()->create(['number' => 'CTR-B', 'title' => 'b', 'partner_name' => 'y', 'status' => Contract::STATUS_ACTIVE, 'expires_at' => now()->addDays(200)->toDateString()]);

    $this->artisan('contracts:alerts', ['--digest' => true])->assertSuccessful();

    Mail::assertSent(ContractAlertsMail::class, function (ContractAlertsMail $mail) {
        return array_column($mail->rows, 'number') === ['CTR-A'];
    });
});

test('the contract mails are signed by Contracts Christian Tour, not by the payables desk', function () {
    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0030', 'title' => 'Test', 'partner_name' => 'Hotel Alfa',
    ]);
    $share = $contract->shares()->create([
        'email' => 'cineva@christiantour.ro', 'permission' => 'view', 'token' => 'token-lung-de-proba',
    ]);

    $shared = (new ContractSharedMail($contract, $share))->envelope();
    $alerts = (new ContractAlertsMail([]))->envelope();

    expect($shared->from?->name)->toBe('Contracts Christian Tour')
        ->and($shared->from?->address)->toBe(config('mail.from.address'))
        ->and($alerts->from?->name)->toBe('Contracts Christian Tour')
        // Restul aplicației rămâne cum era.
        ->and(config('mail.from.name'))->toBe('Receivables & Payables');
});

/** Un contract ca cel de la BVB: două părți, cu sediile lor, și termen scris în litere. */
function romanianContract(): string
{
    return <<<'TEXT'
        CONTRACT DE PRESTARI SERVICII
        Nr. 1/ 09.10.2026

        BURSA DE VALORI BUCURESTI S.A., cu sediul in Bucuresti, Soseaua Nicolae Titulescu nr. 4-8,
        cod unic de inregistrare CUI 17777754, reprezentata legal prin Remus Vulpescu, denumita in
        continuare „Furnizor",
        si
        CHRISTIAN '76 TOUR SA cu sediul in Blvd. Nicolae Balcescu nr 25, Bucuresti, inregistrata la
        Registrul Comertului sub nr. 1997005529405, CUI 9617078, reprezentata prin Stefan Petre,
        denumita in continuare „Beneficiar".

        OBIECTUL CONTRACTULUI
        1.1. Obiectul Contractului il constituie prestarea de catre Furnizor a serviciilor de promovare
        a Beneficiarului prin intermediul portalului online www.bvbresearch.ro.
        1.2. Prezentul Contract intra in vigoare la data semnarii de catre ambele Parti si se aplica
        raporturilor juridice dintre Parti nascute incepand cu data de 1 iulie 2026 si pana la data de
        31 decembrie 2027.
        TEXT;
}

test('the partner is always the other party, never us, however our name is written', function () {
    $fields = app(ContractFields::class)->extract(romanianContract(), ['Christian Tour', 'Christian 76 Tour']);

    expect($fields['partner_name']['value'])->toBe('BURSA DE VALORI BUCURESTI S.A.')
        ->and($fields['partner_name']['confidence'])->toBeGreaterThan(0.85)
        // Codul fiscal e al partenerului, nu al nostru.
        ->and($fields['partner_tax_id']['value'])->toBe('17777754');
});

test('a name that only ends in "SA" is not taken for a company', function () {
    // „BURSA” se termină în „SA” din întâmplare; forma juridică trebuie să fie
    // cuvânt despărțit.
    $fields = app(ContractFields::class)->extract(
        "CONTRACT\nBURSA DE VALORI BUCURESTI S.A., cu sediul in Bucuresti, CUI 17777754, denumita Furnizor",
        ['Christian Tour'],
    );

    expect($fields['partner_name']['value'])->not->toBe('BURSA');
});

test('the term written in words is read, and the second date is the end', function () {
    $fields = app(ContractFields::class)->extract(romanianContract(), ['Christian Tour']);

    expect($fields['expires_at']['value'])->toBe('2027-12-31')
        ->and($fields['signed_at']['value'])->toBe('2026-10-09')
        ->and($fields['number']['value'])->toBe('1');
});

test('the object is the sentence, not the numbering of the article', function () {
    $fields = app(ContractFields::class)->extract(romanianContract(), ['Christian Tour']);

    expect($fields['object']['value'])->toContain('promovare')
        ->and($fields['object']['value'])->not->toStartWith('1.1')
        ->and(mb_strlen($fields['object']['value']))->toBeGreaterThan(30);
});

test('a contract taken in is active from the day it was signed', function () {
    Storage::fake(config('contracts.disk'));

    $this->mock(ContractReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('read')->andReturn([
            'engine' => 'pdftotext', 'text' => romanianContract(), 'pages' => 2, 'status' => 'done', 'error' => null,
        ]);
    });

    $keeper = User::factory()->withRoles('contract_management')->create();

    $this->actingAs($keeper)
        ->post('/contracts', ['files' => [UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf')]])
        ->assertRedirect();

    $contract = Contract::query()->firstOrFail();

    // Starea e „activ” din capul locului, nu ciornă.
    expect($contract->status)->toBe(Contract::STATUS_ACTIVE);

    app(ReadContractFile::class, ['fileId' => $contract->files()->first()->id])
        ->handle(app(ContractReader::class), app(ContractFields::class));

    $contract->refresh();

    // „În vigoare din” urmează data semnării, fără să întrebe pe nimeni.
    expect($contract->signed_at?->toDateString())->toBe('2026-10-09')
        ->and($contract->starts_at?->toDateString())->toBe('2026-10-09')
        ->and($contract->expires_at?->toDateString())->toBe('2027-12-31')
        ->and($contract->partner_name)->toBe('BURSA DE VALORI BUCURESTI S.A.');
});

test('a pointed question is answered with the clause that says it', function () {
    $keeper = User::factory()->withRoles('contract_management')->create();
    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0040', 'title' => 'Prestări', 'partner_name' => 'BVB',
        'created_by_id' => $keeper->id,
    ]);
    ContractFile::query()->create([
        'contract_id' => $contract->id, 'path' => 'x.pdf', 'original_name' => 'x.pdf', 'hash' => 'q1',
        'text' => "1.4. Pretul serviciilor este de 3.750 EUR pe an.\n\n"
            ."1.5. Facturile vor fi platite de Beneficiar in termen de 15 zile calendaristice de la data primirii facturii.\n\n"
            ."4.30. In cazul intarzierii la plata, Beneficiarul datoreaza penalitati de 0,1% pe zi de intarziere.\n\n"
            .'5.1. Contractul poate fi reziliat de oricare dintre parti cu un preaviz de 30 de zile.',
    ]);

    $answer = fn (string $question) => $this->actingAs($keeper)
        ->postJson("/contracts/{$contract->id}/ask", ['question' => $question])
        ->json('answers');

    expect($answer('care este termenul de plata?')[0]['text'])->toContain('15 zile')
        ->and($answer('ce penalitati sunt pentru intarziere?')[0]['text'])->toContain('0,1%')
        ->and($answer('cum se reziliaza?')[0]['text'])->toContain('preaviz')
        // Ce nu scrie în contract nu se inventează.
        ->and($answer('ce scrie despre zborurile charter?'))->toBe([]);

    // Contractul altuia nu răspunde la întrebări.
    $outsider = User::factory()->withRoles('contract_management')->create();
    $this->actingAs($outsider)->postJson("/contracts/{$contract->id}/ask", ['question' => 'ce scrie?'])
        ->assertForbidden();
});

test('the number and the date of an addendum are read from it, not typed', function () {
    Storage::fake(config('contracts.disk'));

    $this->mock(ContractReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('read')->andReturn([
            'engine' => 'pdftotext',
            'status' => 'done',
            'pages' => 1,
            'error' => null,
            'text' => "ACT ADITIONAL nr. 3 / 15.01.2027\n"
                ."la Contractul de prestari servicii nr. 1/09.10.2026\n\n"
                .'Partile convin prelungirea contractului pana la data de 31 decembrie 2028, '
                .'valoarea contractului fiind de 12.500 EUR pe an.',
        ]);
    });

    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0060', 'title' => 'Prestări', 'partner_name' => 'BVB',
        'expires_at' => '2027-12-31',
    ]);
    $file = ContractFile::query()->create([
        'contract_id' => $contract->id, 'kind' => ContractFile::KIND_ADDENDUM,
        'path' => 'a.pdf', 'original_name' => 'act aditional.pdf', 'hash' => 'ha1',
    ]);

    app(ReadContractFile::class, ['fileId' => $file->id])
        ->handle(app(ContractReader::class), app(ContractFields::class));

    $file->refresh();

    expect($file->label)->toBe('nr. 3')
        ->and($file->signed_at?->toDateString())->toBe('2027-01-15')
        ->and($file->title())->toBe('Act adițional nr. 3')
        // Contractul nu se schimbă singur: ce spune actul se propune în jurnal.
        ->and($contract->fresh()->expires_at?->toDateString())->toBe('2027-12-31')
        ->and($contract->events()->where('type', 'ocr_done')->first()->body)
        ->toContain('termen nou: 31.12.2028');
});
