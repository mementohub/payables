<?php

namespace App\Services\Contracts;

use App\Ai\Agents\ContractAnalyst;
use App\Models\Contract;
use App\Models\ContractFile;
use App\Services\Ai\Meter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Întrebarea omului, dusă la agent împreună cu textul contractului.
 *
 * Căutarea prin cuvinte ({@see ContractAsk}) rămâne dedesubt și face două
 * treburi: alege bucățile care contează, când contractul e prea lung ca să
 * încapă întreg, și ține locul agentului când acesta nu răspunde. Omul
 * primește întotdeauna ceva — în cel mai rău caz, clauzele din contract.
 *
 * Aici e singurul loc din modul în care textul unui contract pleacă de pe
 * server: la furnizorul de model, pe drumul pe care merg deja întrebările
 * financiare. Se închide din `contracts.ai.enabled`.
 */
class ContractAnswer
{
    /**
     * Cât text al contractului pleacă într-o întrebare.
     *
     * La vreo 60.000 de caractere (cam 25 de pagini) un contract încape
     * întreg, iar agentul vede tot ce s-a semnat. Peste atât, pleacă numai
     * bucățile care răspund la întrebare: și mai ieftin, și mai la obiect
     * decât tot teancul.
     */
    private const BUDGET = 60000;

    public function __construct(private ContractAsk $ask, private Meter $meter) {}

    /**
     * @return array{question: string, answer: ?string, answers: list<array>, searched: bool, by: string, cost: ?array}
     */
    public function answer(Contract $contract, string $question): array
    {
        $found = $this->ask->ask($contract, $question);

        if (! $found['searched'] || ! $this->on()) {
            return $this->plain($found);
        }

        $text = $this->text($contract, $found['answers']);

        if ($text === '') {
            return $this->plain($found);
        }

        try {
            $response = ContractAnalyst::make()->prompt($this->prompt($contract, $text, $question));
        } catch (Throwable $e) {
            // Agentul e un spor, nu o condiție: dacă tace, rămân clauzele.
            Log::warning('Contract '.$contract->number.': agentul n-a răspuns — '.$e->getMessage());

            return $this->plain($found);
        }

        $said = trim($response->text);
        $cost = $this->meter->record(ContractAnalyst::class, $response, $contract->id);

        return $said === ''
            ? $this->plain($found)
            : [...$found, 'answer' => $said, 'by' => 'ai', 'cost' => $cost];
    }

    /**
     * Răspunsul fără agent: clauzele găsite, fără cost, fiindcă n-a plecat
     * nimic nicăieri.
     *
     * @param  array{question: string, answers: list<array>, searched: bool}  $found
     */
    private function plain(array $found): array
    {
        return [...$found, 'answer' => null, 'by' => 'search', 'cost' => null];
    }

    private function on(): bool
    {
        return (bool) config('contracts.ai.enabled', false)
            && (string) config('ai.providers.openai.key', '') !== '';
    }

    /**
     * Textul care pleacă: contractul întreg cât încape, altfel bucățile găsite.
     *
     * @param  list<array{text: string, document: string}>  $found
     */
    private function text(Contract $contract, array $found): string
    {
        $documents = $contract->files
            ->filter(fn (ContractFile $file) => trim((string) $file->text) !== '')
            ->sortBy(fn (ContractFile $file) => $file->kind === ContractFile::KIND_CONTRACT ? 0 : 1);

        $whole = $documents
            ->map(fn (ContractFile $file) => '--- '.$file->title()." ---\n".trim((string) $file->text))
            ->implode("\n\n");

        if ($whole === '' || mb_strlen($whole) <= self::BUDGET) {
            return $whole;
        }

        // Prea lung: merg bucățile care răspund, fiecare cu hârtia din care e
        // luată, ca agentul să poată spune de unde a citit.
        $pieces = array_map(
            fn (array $piece) => '--- '.$piece['document']." ---\n".$piece['text'],
            $found,
        );

        return mb_substr($pieces === [] ? $whole : implode("\n\n", $pieces), 0, self::BUDGET);
    }

    private function prompt(Contract $contract, string $text, string $question): string
    {
        $about = array_filter([
            'Partener: '.$contract->partner_name,
            $contract->signed_at ? 'Semnat: '.$contract->signed_at->format('d.m.Y') : null,
            $contract->expires_at ? 'Expiră: '.$contract->expires_at->format('d.m.Y') : null,
        ]);

        return "Contract {$contract->number}\n"
            .implode(' · ', $about)."\n\n"
            ."=== TEXTUL CONTRACTULUI ===\n"
            .$text."\n"
            ."=== SFÂRȘITUL TEXTULUI ===\n\n"
            ."Întrebarea colegului: {$question}";
    }
}
