<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractFile;
use App\Models\Partner;
use App\Services\Contracts\ContractFields;
use App\Services\Contracts\ContractReader;
use App\Services\Contracts\ScribeFields;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Citește fișierul abia încărcat și completează ce lipsește din contract.
 *
 * Completează, nu rescrie: ce a pus omul rămâne cum l-a pus. Citirea unui
 * contract scanat de douăzeci de pagini ia minute, deci se face în fundal, iar
 * ecranul arată între timp „se citește”.
 */
class ReadContractFile implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    /**
     * @param  bool  $afresh  Citire din nou, peste ce-a pus tot mașina data
     *                        trecută. Ce-a pus omul cu mâna rămâne neatins.
     */
    public function __construct(public int $fileId, public bool $afresh = false) {}

    /**
     * Unde cele două citiri n-au căzut la învoială.
     *
     * Se trece în jurnal: când tiparul a văzut o dată, iar agentul alta, omul
     * trebuie să afle — de obicei contractul însuși se contrazice, și atunci
     * nu greșește nicio mașină, ci hârtia.
     *
     * @var array<string, array{tipare: string, agent: string}>
     */
    private array $disagreed = [];

    public function handle(ContractReader $reader, ContractFields $fields, ScribeFields $scribe): void
    {
        $file = ContractFile::query()->with('contract')->find($this->fileId);

        if ($file === null) {
            return;
        }

        $disk = Storage::disk((string) config('contracts.disk', 'contracts'));
        $result = $reader->read($disk->path($file->path), $file->mime);

        $file->forceFill([
            'ocr_status' => $result['status'],
            'ocr_engine' => $result['engine'],
            'ocr_error' => $result['error'],
            'pages' => $result['pages'] ?? $file->pages,
            'text' => $result['text'] !== '' ? $result['text'] : null,
        ])->save();

        if ($result['status'] !== ContractFile::OCR_DONE || trim($result['text']) === '') {
            ContractEvent::query()->create([
                'contract_id' => $file->contract_id,
                'type' => 'ocr_failed',
                'body' => $result['error'] ?? 'nu s-a găsit text în document',
                'payload' => ['file_id' => $file->id, 'engine' => $result['engine']],
            ]);

            return;
        }

        // Numele companiilor noastre, ca să nu le ia drept partener: cele din
        // aplicație plus felurile scrise în config.
        // Un act adițional nu completează datele contractului: el le schimbă, iar
        // ce schimbă hotărăște omul. Se citește, se poate căuta în el, iar ce a
        // găsit mașina se scrie în jurnal, ca propunere.
        $isAddendum = in_array($file->kind, [ContractFile::KIND_ADDENDUM, ContractFile::KIND_ANNEX], true);

        $houses = [
            ...Company::query()->pluck('name')->all(),
            ...(array) config('contracts.house_names', []),
        ];
        $read = $this->merge(
            $fields->extract($result['text'], $houses),
            $scribe->extract($result['text'], $houses, $file->contract_id),
        );
        $contract = $file->contract;

        if ($isAddendum) {
            $this->describe($file, $read);

            ContractEvent::query()->create([
                'contract_id' => $contract->id,
                'type' => 'ocr_done',
                'body' => sprintf('%s citit cu %s. %s', $file->fresh()->title(), $result['engine'], $this->proposes($read)),
                'payload' => ['file_id' => $file->id, 'kind' => $file->kind, 'read' => $this->plain($read)],
            ]);

            return;
        }

        $filled = $this->fill($contract, $read);

        ContractEvent::query()->create([
            'contract_id' => $contract->id,
            'type' => 'ocr_done',
            'body' => sprintf(
                'Citit cu %s: %d câmpuri propuse, %d puse în contract.%s',
                $result['engine'],
                count($read),
                count($filled),
                $this->disagreed === [] ? '' : ' De verificat, fiindcă cele două citiri nu se potrivesc: '.implode(', ', array_keys($this->disagreed)).'.',
            ),
            'payload' => [
                'file_id' => $file->id,
                'filled' => $filled,
                'unsure' => ContractFields::unsure($read),
                'disagreed' => $this->disagreed,
            ],
        ]);
    }

    /**
     * Numărul și data actului adițional, luate din el.
     *
     * Omul n-are de ce să le scrie: sunt pe prima pagină a documentului, iar
     * dacă a pus el ceva cu mâna, rămâne ce a pus.
     *
     * @param  array<string, array{value: mixed, confidence: float, source: ?string}>  $read
     */
    private function describe(ContractFile $file, array $read): void
    {
        $changed = [];

        if (blank($file->label) && isset($read['number'])) {
            $changed['label'] = 'nr. '.ltrim((string) $read['number']['value'], 'nr. ');
        }

        if ($file->signed_at === null && isset($read['signed_at'])) {
            $changed['signed_at'] = (string) $read['signed_at']['value'];
        }

        if ($changed !== []) {
            $file->forceFill($changed)->save();
        }
    }

    /**
     * Ce schimbă actul adițional, spus pe scurt în jurnal.
     *
     * @param  array<string, array{value: mixed, confidence: float, source: ?string}>  $read
     */
    private function proposes(array $read): string
    {
        $says = [];

        if (isset($read['expires_at'])) {
            $says[] = 'termen nou: '.CarbonImmutable::parse((string) $read['expires_at']['value'])->format('d.m.Y');
        }

        if (isset($read['value']['value']['amount'])) {
            $says[] = 'valoare: '.number_format((float) $read['value']['value']['amount'], 2, ',', '.').' '.$read['value']['value']['currency'];
        }

        if (isset($read['signed_at'])) {
            $says[] = 'semnat: '.CarbonImmutable::parse((string) $read['signed_at']['value'])->format('d.m.Y');
        }

        return $says === []
            ? 'Nu am găsit în el un termen sau o valoare nouă.'
            : 'Spune '.implode(' · ', $says).'. Dacă așa e, schimbă datele contractului.';
    }

    /** Câmpurile pe care le citește agentul, când ajunge să citească. */
    private const SCRIBE = [
        'partner_name', 'partner_tax_id', 'number', 'object', 'signed_at', 'expires_at',
        'value', 'notice_days', 'payment_terms', 'governing_law', 'auto_renew',
    ];

    /**
     * Ce-a citit agentul peste ce-au găsit tiparele.
     *
     * Agentul citește ca un om și prinde ce tiparele ratează: o durată spusă
     * în cuvinte, un nume rupt de scaner pe două rânduri, un cod fiscal scris
     * cu spații. Tot el știe și să tacă — lasă valoarea goală când prețul e pe
     * zi, nu pe contract, acolo unde tiparul ar fi apucat primul număr văzut.
     *
     * De aceea, dacă s-a văzut că a citit documentul (a umplut câteva
     * câmpuri), el hotărăște pe câmpurile lui, iar tăcerea lui înseamnă „nu
     * scrie în contract”. Dacă n-a răspuns sau n-a priceput mare lucru, rămân
     * tiparele, ca până acum.
     *
     * @param  array<string, array{value: mixed, confidence: float, source: ?string}>  $patterns
     * @param  array<string, array{value: mixed, confidence: float, source: ?string}>  $agent
     * @return array<string, array{value: mixed, confidence: float, source: ?string}>
     */
    private function merge(array $patterns, array $agent): array
    {
        foreach ($agent as $name => $field) {
            $old = $patterns[$name] ?? null;

            if ($old !== null && $this->flat($old['value']) !== $this->flat($field['value'])) {
                $this->disagreed[$name] = ['tipare' => $this->flat($old['value']), 'agent' => $this->flat($field['value'])];
            }
        }

        if (count($agent) < 3) {
            return [...$patterns, ...$agent];
        }

        foreach (self::SCRIBE as $name) {
            unset($patterns[$name]);
        }

        return [...$patterns, ...$agent];
    }

    /**
     * A rămas câmpul așa cum îl pusese mașina data trecută?
     *
     * Dacă da, e al ei și-l poate îndrepta. Dacă nu, l-a scris un om și nu se
     * atinge, oricât de sigură ar fi ea pe citirea nouă.
     */
    private function untouched(mixed $current, mixed $before): bool
    {
        return $before !== null && $this->flat($current) === $this->flat($before);
    }

    /** Aceeași valoare scrisă în două feluri (dată, număr, text) ajunge la același șir. */
    private function flat(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        }

        // Valoarea e o pereche sumă-monedă, nu un singur lucru: se face un
        // șir din amândouă, ca să poată sta lângă cea de dinainte.
        if (is_array($value)) {
            return implode(' ', array_map(fn ($part) => $this->flat($part), $value));
        }

        if (is_bool($value)) {
            return $value ? 'da' : 'nu';
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, array{value: mixed, confidence: float, source: ?string}>  $read
     * @return array<string, mixed>
     */
    private function plain(array $read): array
    {
        return array_map(fn (array $field) => $field['value'], $read);
    }

    /**
     * @param  array<string, array{value: mixed, confidence: float, source: ?string}>  $read
     * @return list<string>
     */
    private function fill(Contract $contract, array $read): array
    {
        // Ce propusese mașina data trecută: la o recitire, numai peste asta
        // se scrie. Ce-a schimbat omul se cunoaște tocmai fiindcă nu mai
        // seamănă cu ce propusese ea.
        $was = $contract->ocr_fields ?? [];
        $ocr = $was;
        $filled = [];

        foreach ($read as $name => $field) {
            $ocr[$name] = ['confidence' => round($field['confidence'], 2), 'source' => $field['source'], 'value' => $field['value']];
        }

        $set = function (string $column, mixed $value, ?string $name = null) use ($contract, &$filled, $was): void {
            $current = $contract->{$column};

            // Linia de dinainte („—”) e locul gol cu care se naște contractul
            // la încărcare, nu un răspuns de-al omului.
            if (is_string($current) && in_array(trim($current), ['—', '-'], true)) {
                $current = null;
            }

            if ($value === null || $value === '') {
                return;
            }

            // Numai ce e gol: o corectură de om nu se mai mișcă de la locul ei.
            // La o recitire se mișcă și ce pusese tot mașina, dacă acum vede
            // altceva — dar numai dacă de atunci n-a umblat nimeni la câmp.
            if (filled($current) && ! ($this->afresh && $this->untouched($current, $was[$name ?? $column]['value'] ?? null))) {
                return;
            }

            if ((string) $this->flat($current) === (string) $this->flat($value)) {
                return;
            }

            $contract->{$column} = $value;
            $filled[] = $column;
        };

        $set('partner_name', $read['partner_name']['value'] ?? null);
        $set('partner_tax_id', $read['partner_tax_id']['value'] ?? null);
        $set('object', $read['object']['value'] ?? null);
        $set('signed_at', $read['signed_at']['value'] ?? null);
        $set('expires_at', $read['expires_at']['value'] ?? null);
        $set('notice_days', $read['notice_days']['value'] ?? null);
        $set('payment_terms', $read['payment_terms']['value'] ?? null);
        $set('governing_law', $read['governing_law']['value'] ?? null);

        if (isset($read['value']['value']['amount'])) {
            $before = $was['value']['value']['amount'] ?? null;

            if (! filled($contract->value) || ($this->afresh && $this->untouched($contract->value, $before))) {
                if ((float) $contract->value !== (float) $read['value']['value']['amount']) {
                    $filled[] = 'value';
                }

                $contract->value = $read['value']['value']['amount'];
                $contract->currency = $read['value']['value']['currency'];
            }
        }

        if (($read['auto_renew']['value'] ?? false) === true && ! $contract->auto_renew) {
            $contract->auto_renew = true;
            $filled[] = 'auto_renew';
        }

        // Partenerul citit se leagă de fișa lui, dacă îl recunoaștem: din
        // contract se ajunge la facturile lui și invers.
        if ($contract->partner_id === null && filled($contract->partner_name)) {
            $partner = $this->match($contract->partner_name, $contract->partner_tax_id);

            if ($partner !== null) {
                $contract->partner_id = $partner->id;
                $filled[] = 'partner_id';
            }
        }

        // „În vigoare din” e data semnării: așa spun contractele („intră în
        // vigoare la data semnării de către părți”), iar cine vrea altceva o
        // schimbă cu mâna.
        if ($contract->signed_at !== null) {
            $contract->starts_at = $contract->signed_at;
        }

        $contract->ocr_fields = $ocr;
        $contract->save();

        return $filled;
    }

    private function match(string $name, ?string $taxId): ?Partner
    {
        if ($taxId !== null && $taxId !== '') {
            $digits = preg_replace('~\D~', '', $taxId);
            $partner = Partner::query()->where('cui', 'like', '%'.$digits)->first();

            if ($partner !== null) {
                return $partner;
            }
        }

        $folded = mb_strtolower(trim((string) preg_replace('~\b(s\.?r\.?l|s\.?a|gmbh|ltd|srl)\.?\b~ui', '', $name)));

        return $folded === '' ? null : Partner::query()->where('name', 'like', $folded.'%')->first();
    }
}
