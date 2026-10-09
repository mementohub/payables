<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractFile;
use App\Models\Partner;
use App\Services\Contracts\ContractFields;
use App\Services\Contracts\ContractReader;
use Carbon\CarbonImmutable;
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

    public function __construct(public int $fileId) {}

    public function handle(ContractReader $reader, ContractFields $fields): void
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
        $read = $fields->extract($result['text'], $houses);
        $contract = $file->contract;

        if ($isAddendum) {
            ContractEvent::query()->create([
                'contract_id' => $contract->id,
                'type' => 'ocr_done',
                'body' => sprintf('%s citit cu %s. %s', $file->title(), $result['engine'], $this->proposes($read)),
                'payload' => ['file_id' => $file->id, 'kind' => $file->kind, 'read' => $this->plain($read)],
            ]);

            return;
        }

        $filled = $this->fill($contract, $read);

        ContractEvent::query()->create([
            'contract_id' => $contract->id,
            'type' => 'ocr_done',
            'body' => sprintf('Citit cu %s: %d câmpuri propuse, %d puse în contract.', $result['engine'], count($read), count($filled)),
            'payload' => ['file_id' => $file->id, 'filled' => $filled, 'unsure' => ContractFields::unsure($read)],
        ]);
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
        $ocr = $contract->ocr_fields ?? [];
        $filled = [];

        foreach ($read as $name => $field) {
            $ocr[$name] = ['confidence' => round($field['confidence'], 2), 'source' => $field['source'], 'value' => $field['value']];
        }

        $set = function (string $column, mixed $value) use ($contract, &$filled): void {
            $current = $contract->{$column};

            // Linia de dinainte („—”) e locul gol cu care se naște contractul
            // la încărcare, nu un răspuns de-al omului.
            if (is_string($current) && in_array(trim($current), ['—', '-'], true)) {
                $current = null;
            }

            // Numai ce e gol: o corectură de om nu se mai mișcă de la locul ei.
            if ($value === null || $value === '' || filled($current)) {
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

        if (isset($read['value']['value']['amount']) && ! filled($contract->value)) {
            $contract->value = $read['value']['value']['amount'];
            $contract->currency = $read['value']['value']['currency'];
            $filled[] = 'value';
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
