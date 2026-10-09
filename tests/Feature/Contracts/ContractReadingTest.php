<?php

use App\Jobs\ReadContractFile;
use App\Mail\ContractAlertsMail;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractFile;
use App\Models\Partner;
use App\Models\User;
use App\Services\Contracts\ContractFields;
use App\Services\Contracts\ContractReader;
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
    $contract = \App\Models\Contract::query()->create([
        'number' => 'CTR-2026-0030', 'title' => 'Test', 'partner_name' => 'Hotel Alfa',
    ]);
    $share = $contract->shares()->create([
        'email' => 'cineva@christiantour.ro', 'permission' => 'view', 'token' => 'token-lung-de-proba',
    ]);

    $shared = (new \App\Mail\ContractSharedMail($contract, $share))->envelope();
    $alerts = (new ContractAlertsMail([]))->envelope();

    expect($shared->from?->name)->toBe('Contracts Christian Tour')
        ->and($shared->from?->address)->toBe(config('mail.from.address'))
        ->and($alerts->from?->name)->toBe('Contracts Christian Tour')
        // Restul aplicației rămâne cum era.
        ->and(config('mail.from.name'))->toBe('Receivables & Payables');
});
