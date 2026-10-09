<?php

use App\Ai\Agents\ContractScribe;
use App\Jobs\ReadContractFile;
use App\Models\AiUsage;
use App\Models\Contract;
use App\Models\ContractFile;
use App\Models\User;
use App\Services\Ai\Meter;
use App\Services\Contracts\ContractFields;
use App\Services\Contracts\ContractReader;
use App\Services\Contracts\ScribeFields;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

beforeEach(function () {
    Storage::fake(config('contracts.disk'));
    config(['contracts.ai.enabled' => true, 'ai.providers.openai.key' => 'cheie-de-test']);

    $this->keeper = User::factory()->withRoles('contract_management')->create();
});

/** Un răspuns de-al agentului, cum vine el de la furnizor. */
function said(array $fields): array
{
    $all = [];

    foreach (['partner_name', 'partner_tax_id', 'number', 'object', 'signed_at', 'expires_at',
        'value_amount', 'value_currency', 'notice_days', 'payment_terms', 'governing_law', 'auto_renew'] as $name) {
        $all[$name] = ['value' => $fields[$name] ?? null, 'confidence' => 1, 'quote' => $fields[$name] ?? null ? 'scrie în contract' : null];
    }

    return $all;
}

test('the agent reads the fields, and what it reads goes into the file', function () {
    ContractScribe::fake([said([
        'partner_name' => 'Societatea All About Parenting Systems S.R.L.',
        'partner_tax_id' => 'RO 5109312',
        'signed_at' => '2025-09-01',
        'expires_at' => '2026-03-01',
        'value_amount' => 1500,
        'value_currency' => 'EUR',
        'notice_days' => 7,
        'object' => 'Schimbul de produse sau servicii dintre părți.',
    ])]);

    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0100', 'title' => 'Parenting', 'partner_name' => '—',
        'created_by_id' => $this->keeper->id,
    ]);
    $file = ContractFile::query()->create([
        'contract_id' => $contract->id, 'kind' => ContractFile::KIND_CONTRACT, 'version' => 1,
        'path' => 'c.txt', 'original_name' => 'contract.txt', 'hash' => 's1', 'mime' => 'text/plain',
    ]);
    Storage::disk(config('contracts.disk'))->put('c.txt', 'Contract incheiat astazi, 01.09.2025, intre Societatea All About Parenting Systems S.R.L. si Christian Tour.');

    (new ReadContractFile($file->id))->handle(
        app(ContractReader::class),
        app(ContractFields::class),
        app(ScribeFields::class),
    );

    $contract->refresh();

    expect($contract->partner_name)->toBe('Societatea All About Parenting Systems S.R.L.')
        // Codul fiscal intră strâns, oricum ar fi scris în contract.
        ->and($contract->partner_tax_id)->toBe('RO5109312')
        ->and($contract->signed_at->toDateString())->toBe('2025-09-01')
        ->and($contract->expires_at->toDateString())->toBe('2026-03-01')
        // „În vigoare din” rămâne data semnării.
        ->and($contract->starts_at->toDateString())->toBe('2025-09-01')
        ->and((float) $contract->value)->toBe(1500.0)
        ->and($contract->notice_days)->toBe(7);
});

test('a long-winded reading is trimmed to what the file can hold', function () {
    // Agentul mai scapă o frază întreagă acolo unde fișa are loc de un rând.
    // Dacă n-o scurtăm, baza refuză rândul și se pierde tot ce-a citit bine.
    ContractScribe::fake([said([
        'partner_name' => str_repeat('Societatea Foarte Lungă și Pomposă SRL ', 20),
        'partner_tax_id' => 'nu scrie codul fiscal nicăieri în contract',
        'signed_at' => '2026-02-12',
        'governing_law' => str_repeat('legea română ', 30),
    ])]);

    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0104', 'title' => 'Lung', 'partner_name' => '—',
        'created_by_id' => $this->keeper->id,
    ]);
    $file = ContractFile::query()->create([
        'contract_id' => $contract->id, 'kind' => ContractFile::KIND_CONTRACT, 'version' => 1,
        'path' => 'f.txt', 'original_name' => 'contract.txt', 'hash' => 's4', 'mime' => 'text/plain',
    ]);
    Storage::disk(config('contracts.disk'))->put('f.txt', 'Un contract oarecare, încheiat la 12.02.2026.');

    (new ReadContractFile($file->id))->handle(
        app(App\Services\Contracts\ContractReader::class),
        app(App\Services\Contracts\ContractFields::class),
        app(App\Services\Contracts\ScribeFields::class),
    );

    $contract->refresh();

    expect(mb_strlen($contract->partner_name))->toBeLessThanOrEqual(200)
        ->and(mb_strlen((string) $contract->governing_law))->toBeLessThanOrEqual(80)
        // O frază nu e cod fiscal: mai bine gol decât greșit.
        ->and($contract->partner_tax_id)->toBeNull()
        ->and($contract->signed_at->toDateString())->toBe('2026-02-12');
});

test('a re-read mends what the machine got wrong, but never what a person wrote', function () {
    ContractScribe::fake([
        said(['partner_name' => 'ALFA SRL', 'signed_at' => '2025-09-01', 'expires_at' => '2025-04-30']),
        said(['partner_name' => 'ALFA SRL', 'signed_at' => '2025-09-01', 'expires_at' => '2026-03-01']),
    ]);

    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0101', 'title' => 'Alfa', 'partner_name' => '—',
        'created_by_id' => $this->keeper->id,
    ]);
    $file = ContractFile::query()->create([
        'contract_id' => $contract->id, 'kind' => ContractFile::KIND_CONTRACT, 'version' => 1,
        'path' => 'd.txt', 'original_name' => 'contract.txt', 'hash' => 's2', 'mime' => 'text/plain',
    ]);
    Storage::disk(config('contracts.disk'))->put('d.txt', 'Contract intre ALFA SRL si Christian Tour, incheiat la 01.09.2025.');

    $read = fn (bool $afresh) => (new ReadContractFile($file->id, $afresh))->handle(
        app(ContractReader::class),
        app(ContractFields::class),
        app(ScribeFields::class),
    );

    $read(false);

    expect($contract->refresh()->expires_at->toDateString())->toBe('2025-04-30');

    // Omul schimbă titlul cu mâna; citirea nouă n-are voie să i-l mute.
    $contract->update(['title' => 'Cum îi spun eu']);

    $read(true);
    $contract->refresh();

    expect($contract->expires_at->toDateString())->toBe('2026-03-01')
        ->and($contract->title)->toBe('Cum îi spun eu');
});

test('when the agent is quiet, the patterns still fill the file', function () {
    ContractScribe::fake(function () {
        throw new RuntimeException('furnizorul tace');
    });

    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0102', 'title' => 'Beta', 'partner_name' => '—',
        'created_by_id' => $this->keeper->id,
    ]);
    $file = ContractFile::query()->create([
        'contract_id' => $contract->id, 'kind' => ContractFile::KIND_CONTRACT, 'version' => 1,
        'path' => 'e.txt', 'original_name' => 'contract.txt', 'hash' => 's3', 'mime' => 'text/plain',
    ]);
    // Tiparele cer așezarea obișnuită a unui contract: titlu, număr, părțile
    // cu sediul lor. Tocmai de aici vine folosul agentului — el citește și
    // contractele scrise altfel.
    Storage::disk(config('contracts.disk'))->put('e.txt', "CONTRACT DE PRESTARI SERVICII\nNr. 42 din 12.02.2026\n\nIncheiat intre:\nBETA COM SRL, cu sediul in Bucuresti, CUI RO123456, in calitate de Prestator,\nsi\nChristian Tour SA, cu sediul in Bucuresti, in calitate de Beneficiar.");

    (new ReadContractFile($file->id))->handle(
        app(ContractReader::class),
        app(ContractFields::class),
        app(ScribeFields::class),
    );

    expect($contract->refresh()->signed_at->toDateString())->toBe('2026-02-12')
        ->and($contract->partner_name)->toBe('BETA COM SRL')
        ->and($contract->partner_tax_id)->toBe('RO123456');
});

test('the meter writes down what each question cost', function () {
    $contract = Contract::query()->create([
        'number' => 'CTR-2026-0103', 'title' => 'Gama', 'partner_name' => 'Gama SRL',
        'created_by_id' => $this->keeper->id,
    ]);

    $response = new AgentResponse(
        'inv-1',
        'un răspuns',
        new Usage(promptTokens: 1_000_000, completionTokens: 100_000),
        new Meta(provider: 'openai', model: 'gpt-5.4-2026-03-05'),
    );

    config(['ai_costs.prices' => ['gpt-5.4' => ['in' => 1.25, 'out' => 10.00]], 'ai_costs.usd_ron' => 5.0]);

    $cost = app(Meter::class)->record('App\Ai\Agents\ContractAnalyst', $response, $contract->id);

    // Un milion citiți la 1,25 $ plus o sută de mii scriși la 10 $ fac 2,25 $.
    expect($cost['usd'])->toBe(2.25)
        ->and($cost['lei'])->toBe(11.25)
        ->and($cost['month_usd'])->toBe(2.25)
        ->and(AiUsage::query()->where('contract_id', $contract->id)->value('model'))->toBe('gpt-5.4-2026-03-05');
});

test('a model with no price shows tokens instead of a made-up figure', function () {
    config(['ai_costs.prices' => []]);

    $response = new AgentResponse(
        'inv-2', 'ceva',
        new Usage(promptTokens: 500, completionTokens: 50),
        new Meta(provider: 'openai', model: 'model-necunoscut'),
    );

    $cost = app(Meter::class)->record('App\Ai\Agents\ContractAnalyst', $response);

    expect($cost['usd'])->toBeNull()
        ->and($cost['lei'])->toBeNull()
        ->and($cost['tokens_in'])->toBe(500);
});
