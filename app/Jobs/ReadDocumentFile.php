<?php

namespace App\Jobs;

use App\Models\CompanyDocument;
use App\Models\CompanyDocumentEvent;
use App\Models\CompanyDocumentFile;
use App\Services\Contracts\ContractReader;
use App\Services\Documents\DocumentFields;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Citește fișierul abia încărcat și completează ce lipsește din decizie sau
 * din procedură.
 *
 * Completează, nu rescrie: ce a pus omul rămâne cum l-a pus. Citirea unei
 * scanări de douăzeci de pagini ia minute, deci se face în fundal, iar ecranul
 * arată între timp „se citește”.
 */
class ReadDocumentFile implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    /**
     * @param  bool  $afresh  Citire din nou, peste ce-a pus tot mașina data
     *                        trecută. Ce-a pus omul cu mâna rămâne neatins.
     */
    public function __construct(public int $fileId, public bool $afresh = false) {}

    public function handle(ContractReader $reader, DocumentFields $fields): void
    {
        $file = CompanyDocumentFile::query()->with('document')->find($this->fileId);

        if ($file === null) {
            return;
        }

        $disk = Storage::disk((string) config('documents.disk', 'documents'));
        $result = $reader->read($disk->path($file->path), $file->mime);

        $file->forceFill([
            'ocr_status' => $result['status'],
            'ocr_engine' => $result['engine'],
            'ocr_error' => $result['error'],
            'pages' => $result['pages'] ?? $file->pages,
            'text' => $result['text'] !== '' ? $result['text'] : null,
        ])->save();

        $document = $file->document;

        if ($result['status'] !== CompanyDocumentFile::OCR_DONE || trim($result['text']) === '') {
            CompanyDocumentEvent::query()->create([
                'company_document_id' => $file->company_document_id,
                'type' => 'ocr_failed',
                'body' => $result['error'] ?? 'nu s-a găsit text în document',
                'payload' => ['file_id' => $file->id, 'engine' => $result['engine']],
            ]);

            return;
        }

        // Anexa nu schimbă datele documentului: ea se citește ca să poată fi
        // căutată, atât.
        if ($file->kind === CompanyDocumentFile::KIND_ANNEX) {
            CompanyDocumentEvent::query()->create([
                'company_document_id' => $document->id,
                'type' => 'ocr_done',
                'body' => sprintf('%s citită cu %s.', $file->title(), $result['engine']),
                'payload' => ['file_id' => $file->id, 'kind' => $file->kind],
            ]);

            return;
        }

        $read = $fields->extract($result['text'], $document->type);
        $filled = $this->fill($document, $read);

        CompanyDocumentEvent::query()->create([
            'company_document_id' => $document->id,
            'type' => 'ocr_done',
            'body' => sprintf(
                'Citit cu %s: %d câmpuri propuse, %d puse în document.',
                $result['engine'],
                count($read),
                count($filled),
            ),
            'payload' => [
                'file_id' => $file->id,
                'filled' => $filled,
                'unsure' => DocumentFields::unsure($read),
            ],
        ]);
    }

    /**
     * Ce-a citit mașina, pus în document — numai acolo unde n-a scris omul.
     *
     * @param  array<string, array{value: mixed, confidence: float, source: ?string}>  $read
     * @return list<string>
     */
    private function fill(CompanyDocument $document, array $read): array
    {
        // Ce propusese mașina data trecută: la o recitire, numai peste asta se
        // scrie. Ce-a schimbat omul se cunoaște tocmai fiindcă nu mai seamănă
        // cu ce propusese ea.
        $was = $document->ocr_fields ?? [];
        $ocr = $was;
        $filled = [];

        foreach ($read as $name => $field) {
            $ocr[$name] = ['confidence' => round($field['confidence'], 2), 'source' => $field['source'], 'value' => $field['value']];
        }

        $set = function (string $column, mixed $value, string $name) use ($document, &$filled, $was): void {
            $current = $document->{$column};

            if ($value === null || $value === '') {
                return;
            }

            // Numai ce e gol — sau ce a pus tot mașina și n-a atins nimeni de
            // atunci. O corectură de om nu se mai mișcă de la locul ei.
            //
            // Titlul se naște din numele fișierului și se trece de la bun
            // început în dreptul mașinii, tocmai ca citirea să-l poată
            // înlocui cu titlul adevărat din document.
            if (filled($current) && ! $this->untouched($current, $was[$name]['value'] ?? null)) {
                return;
            }

            if ($this->flat($current) === $this->flat($value)) {
                return;
            }

            $document->{$column} = $value;
            $filled[] = $name;
        };

        $set('title', $read['title']['value'] ?? null, 'title');
        $set('object', $read['object']['value'] ?? null, 'object');
        $set('issuer', $read['issuer']['value'] ?? null, 'issuer');
        $set('issued_at', $read['issued_at']['value'] ?? null, 'issued_at');
        $set('effective_at', $read['effective_at']['value'] ?? null, 'effective_at');

        // La o recitire, ce nu mai vede mașina se golește — dar numai dacă tot
        // ea îl pusese. Un câmp gol se vede și se completează; unul rămas
        // greșit trece drept adevăr.
        if ($this->afresh) {
            foreach (['issuer', 'issued_at', 'effective_at', 'object'] as $column) {
                if (isset($read[$column]) || ! filled($document->{$column})) {
                    continue;
                }

                if ($this->untouched($document->{$column}, $was[$column]['value'] ?? null)) {
                    $document->{$column} = null;
                    $filled[] = $column.' (golit)';
                }
            }
        }

        // Numărul citit din document se ține minte ca propunere, dar nu
        // înlocuiește numărul din registru: ăla e al nostru și e unic.
        $document->ocr_fields = $ocr;
        $document->save();

        return $filled;
    }

    /**
     * A rămas câmpul așa cum îl pusese mașina data trecută? Dacă da, e al ei
     * și-l poate îndrepta; dacă nu, l-a scris un om și nu se atinge.
     */
    private function untouched(mixed $current, mixed $before): bool
    {
        if ($before === null) {
            return false;
        }

        return $this->flat($current) === $this->flat($before);
    }

    private function flat(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) $value);
    }
}
