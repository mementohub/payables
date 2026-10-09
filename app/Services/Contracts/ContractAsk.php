<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractFile;

/**
 * Întrebări punctuale despre un contract anume.
 *
 * Nu inventează nimic și nu trimite nimic în afară: caută în chiar textul
 * contractului și întoarce bucățile care răspund, cu cuvintele întrebării
 * îngroșate. Un om vede în două secunde ce scrie la penalități, fără să
 * citească douăzeci de pagini.
 *
 * Când va exista un model de limbă la îndemână — pe serverul ăsta sau pe altă
 * mașină din rețeaua firmei — peste bucățile astea se va pune un răspuns scris
 * în cuvinte. Până atunci, bucățile adevărate sunt mai de folos decât o
 * ghicitoare.
 */
class ContractAsk
{
    /** Cuvinte care nu ajută la căutare: sunt în orice frază. */
    private const NOISE = [
        'care', 'este', 'sunt', 'pentru', 'despre', 'unde', 'cand', 'cat', 'cum', 'ce', 'scrie',
        'contract', 'contractul', 'contractului', 'prezentul', 'partile', 'partea', 'the', 'what',
        'when', 'where', 'how', 'much', 'does', 'this', 'agreement', 'and', 'with', 'from', 'are',
        'cu', 'de', 'la', 'in', 'pe', 'si', 'sau', 'un', 'o', 'al', 'ale', 'lui', 'se', 'am', 'ai',
    ];

    /**
     * Cuvintele pe care le caută oamenii și rudele lor din contracte: cine
     * întreabă de „penalități” vrea și „dobânda de întârziere”.
     */
    private const KIN = [
        'penalitate' => ['penalit', 'daune', 'dobanda', 'intarziere', 'majorar'],
        'plata' => ['plat', 'factur', 'scadent', 'termen de plata', 'pret'],
        'reziliere' => ['rezilier', 'denunt', 'incetare', 'inceteaza', 'preaviz'],
        'garantie' => ['garanti', 'scrisoare de garantie', 'retiner'],
        'confidential' => ['confidential', 'secret', 'nedivulgare'],
        'raspundere' => ['raspunder', 'despagubir', 'prejudici', 'forta majora'],
        'durata' => ['durat', 'valabil', 'in vigoare', 'prelungir', 'reinnoire'],
        'livrare' => ['livrar', 'predare', 'receptie', 'termen de executie'],
    ];

    /**
     * @return array{question: string, answers: list<array{text: string, score: float, words: list<string>, document: string}>, searched: bool}
     */
    public function ask(Contract $contract, string $question): array
    {
        $words = $this->words($question);

        if ($words === []) {
            return ['question' => $question, 'answers' => [], 'searched' => true];
        }

        // Se caută în contract și în actele lui adiționale: ce s-a schimbat
        // printr-un act adițional e tot atât de în vigoare ca textul de bază,
        // iar cine întreabă nu trebuie să știe în care hârtie scrie.
        $documents = $contract->files
            ->filter(fn (ContractFile $file) => $file->text !== null)
            ->sortBy(fn (ContractFile $file) => $file->kind === ContractFile::KIND_CONTRACT ? 0 : 1);

        if ($documents->isEmpty()) {
            return ['question' => $question, 'answers' => [], 'searched' => false];
        }

        $scored = [];

        foreach ($documents as $document) {
            // Actul adițional bate contractul: el e cel care a schimbat ceva.
            $weight = $document->kind === ContractFile::KIND_CONTRACT ? 1.0 : 1.15;

            foreach ($this->paragraphs((string) $document->text) as $paragraph) {
                $flat = $this->fold($paragraph);
                $hits = array_values(array_filter($words, fn (string $word) => str_contains($flat, $word)));

                if ($hits === []) {
                    continue;
                }

                // Contează câte cuvinte ale întrebării se regăsesc, nu de câte
                // ori: un paragraf care le atinge pe toate bate unul care
                // repetă unul.
                $score = (count($hits) / count($words) + min(0.3, mb_strlen($paragraph) / 4000)) * $weight;
                $scored[] = [
                    'text' => $paragraph,
                    'score' => round($score, 3),
                    'words' => $hits,
                    'document' => $document->title(),
                ];
            }
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return [
            'question' => $question,
            'answers' => array_slice($scored, 0, 5),
            'searched' => true,
        ];
    }

    /**
     * Paragrafele contractului: articolele, cum sunt scrise în el.
     *
     * @return list<string>
     */
    private function paragraphs(string $text): array
    {
        $text = (string) preg_replace('~[ \t]+~u', ' ', $text);
        $pieces = preg_split('~\n\s*\n|\n(?=\s*\d+(?:\.\d+)*\.?\s)~u', $text) ?: [];

        $paragraphs = [];

        foreach ($pieces as $piece) {
            $piece = trim((string) preg_replace('~\s*\n\s*~u', ' ', $piece));

            if (mb_strlen($piece) < 40) {
                continue;
            }

            // Bucățile lungi se taie pe fraze, ca răspunsul să încapă pe ecran.
            foreach (mb_str_split($piece, 900) as $slice) {
                $paragraphs[] = trim($slice);
            }
        }

        return $paragraphs;
    }

    /**
     * Cuvintele după care merită căutat, plus rudele lor.
     *
     * @return list<string>
     */
    private function words(string $question): array
    {
        $flat = $this->fold($question);
        $words = array_values(array_filter(
            preg_split('~[^a-z0-9]+~u', $flat) ?: [],
            fn (string $word) => mb_strlen($word) > 2 && ! in_array($word, self::NOISE, true),
        ));

        $all = [];

        foreach ($words as $word) {
            // Se caută rădăcina, ca „penalitățile” să prindă „penalitate”.
            $all[] = mb_substr($word, 0, max(4, mb_strlen($word) - 2));

            foreach (self::KIN as $topic => $kin) {
                if (str_starts_with($topic, mb_substr($word, 0, 5)) || str_starts_with($word, mb_substr($topic, 0, 5))) {
                    $all = [...$all, ...$kin];
                }
            }
        }

        return array_values(array_unique($all));
    }

    private function fold(string $value): string
    {
        return (string) preg_replace('~\s+~u', ' ', strtr(mb_strtolower($value), [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]));
    }
}
