<?php

namespace App\Services\Documents;

use App\Ai\Agents\DocumentAnalyst;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentFile;
use App\Services\Ai\Meter;
use App\Services\Contracts\ContractAsk;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Întrebarea omului despre o decizie sau o procedură.
 *
 * Căutarea prin text rămâne dedesubt și face două treburi, ca la contracte:
 * alege bucățile care contează și ține locul agentului când acesta nu răspunde.
 * Omul primește întotdeauna ceva — în cel mai rău caz, chiar rândurile din
 * document.
 */
class DocumentAnswer
{
    /** Cât text pleacă într-o întrebare: vreo 25 de pagini. */
    private const BUDGET = 60000;

    public function __construct(private ContractAsk $ask, private Meter $meter) {}

    /**
     * @return array{question: string, answer: ?string, answers: list<array>, searched: bool, by: string, cost: ?array}
     */
    public function answer(CompanyDocument $document, string $question): array
    {
        $found = $this->ask->search($this->pieces($document), $question);

        if (! $found['searched'] || ! $this->on()) {
            return $this->plain($found);
        }

        $text = $this->text($document, $found['answers']);

        if ($text === '') {
            return $this->plain($found);
        }

        try {
            $response = DocumentAnalyst::make()->prompt($this->prompt($document, $text, $question));
        } catch (Throwable $e) {
            // Agentul e un spor, nu o condiție: dacă tace, rămâne textul.
            Log::warning($document->label().' '.$document->number.': agentul n-a răspuns — '.$e->getMessage());

            return $this->plain($found);
        }

        $said = trim($response->text);
        $cost = $this->meter->record(DocumentAnalyst::class, $response);

        return $said === ''
            ? $this->plain($found)
            : [...$found, 'answer' => $said, 'by' => 'ai', 'cost' => $cost];
    }

    /**
     * Hârtiile documentului, cu documentul însuși înaintea anexelor.
     *
     * @return list<array{title: string, text: string, weight: float}>
     */
    private function pieces(CompanyDocument $document): array
    {
        return $document->files
            ->filter(fn (CompanyDocumentFile $file) => trim((string) $file->text) !== '')
            ->sortBy(fn (CompanyDocumentFile $file) => $file->kind === CompanyDocumentFile::KIND_DOCUMENT ? 0 : 1)
            ->map(fn (CompanyDocumentFile $file) => [
                'title' => $file->title(),
                'text' => (string) $file->text,
                'weight' => 1.0,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{question: string, answers: list<array>, searched: bool}  $found
     * @return array{question: string, answer: ?string, answers: list<array>, searched: bool, by: string, cost: ?array}
     */
    private function plain(array $found): array
    {
        return [...$found, 'answer' => null, 'by' => 'search', 'cost' => null];
    }

    private function on(): bool
    {
        return (bool) config('documents.ai.enabled', false)
            && (string) config('ai.providers.openai.key', '') !== '';
    }

    /**
     * Textul care pleacă: documentul întreg cât încape, altfel bucățile găsite.
     *
     * @param  list<array{text: string, document: string}>  $found
     */
    private function text(CompanyDocument $document, array $found): string
    {
        $whole = collect($this->pieces($document))
            ->map(fn (array $piece) => '--- '.$piece['title']." ---\n".trim($piece['text']))
            ->implode("\n\n");

        if ($whole === '' || mb_strlen($whole) <= self::BUDGET) {
            return $whole;
        }

        $pieces = array_map(
            fn (array $piece) => '--- '.$piece['document']." ---\n".$piece['text'],
            $found,
        );

        return mb_substr($pieces === [] ? $whole : implode("\n\n", $pieces), 0, self::BUDGET);
    }

    private function prompt(CompanyDocument $document, string $text, string $question): string
    {
        $about = array_filter([
            $document->issuer !== null ? 'Emitent: '.$document->issuer : null,
            $document->issued_at !== null ? 'Emisă: '.$document->issued_at->format('d.m.Y') : null,
            $document->effective_at !== null ? 'În vigoare din: '.$document->effective_at->format('d.m.Y') : null,
        ]);

        return $document->label('article').' '.$document->number."\n"
            .$document->title."\n"
            .implode(' · ', $about)."\n\n"
            ."=== TEXTUL DOCUMENTULUI ===\n"
            .$text."\n"
            ."=== SFÂRȘITUL TEXTULUI ===\n\n"
            ."Întrebarea colegului: {$question}";
    }
}
