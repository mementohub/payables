<?php

namespace App\Services\Documents;

use App\Models\CompanyDocument;
use Carbon\CarbonImmutable;

/**
 * Ce se poate citi dintr-o decizie sau dintr-o procedură, fără să plece nimic
 * de pe server.
 *
 * Hârtiile noastre sunt scrise după un tipar destul de statornic: titlul sus,
 * numărul și data sub el, „privind…” pentru obiect, iar la coadă cine semnează.
 * Tiparele de aici urmăresc chiar tiparul ăsta; ce nu se potrivește rămâne gol
 * și-l scrie omul.
 *
 * Fiecare câmp vine cu cât de sigur e: sub 85% ecranul îl arată galben, ca să
 * i se arunce o privire.
 */
class DocumentFields
{
    /** De aici în sus, câmpul se socotește citit bine. */
    private const SURE = 0.85;

    private const MONTHS = [
        'ianuarie' => 1, 'februarie' => 2, 'martie' => 3, 'aprilie' => 4, 'mai' => 5, 'iunie' => 6,
        'iulie' => 7, 'august' => 8, 'septembrie' => 9, 'octombrie' => 10, 'noiembrie' => 11, 'decembrie' => 12,
    ];

    /**
     * Cine semnează hârtiile casei. Se caută după funcție, nu după nume:
     * numele se schimbă, funcția rămâne.
     */
    private const ROLES = [
        'administrator unic', 'administrator', 'director general', 'director executiv',
        'director economic', 'director financiar', 'presedinte', 'director resurse umane',
        'manager general', 'director de operatiuni', 'director',
    ];

    /**
     * Tot ce se poate scoate din text.
     *
     * @return array<string, array{value: mixed, confidence: float, source: ?string}>
     */
    public function extract(string $text, string $type): array
    {
        $flat = $this->fold($text);
        $head = mb_substr($text, 0, 3000);
        $flatHead = $this->fold($head);

        return array_filter([
            'number' => $this->number($flatHead, $type),
            'issued_at' => $this->issued($flatHead),
            'effective_at' => $this->effective($flat),
            'title' => $this->title($text, $head),
            'object' => $this->object($text),
            'issuer' => $this->issuer($flat),
        ], fn (?array $field) => $field !== null);
    }

    /**
     * Câmpurile pe care mașina nu e sigură: se trec în jurnal, ca omul să știe
     * ce merită verificat.
     *
     * @param  array<string, array{value: mixed, confidence: float, source: ?string}>  $fields
     * @return list<string>
     */
    public static function unsure(array $fields): array
    {
        return array_keys(array_filter($fields, fn (array $field) => $field['confidence'] < self::SURE));
    }

    /**
     * Numărul hârtiei: „Decizia nr. 12 din 03.02.2026”, „PROCEDURA PO-HR-05”.
     *
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function number(string $flat, string $type): ?array
    {
        $word = $type === CompanyDocument::TYPE_PROCEDURE ? 'procedur\w*' : 'deciz\w*';

        $patterns = [
            // Chiar lângă cuvântul care spune ce e hârtia.
            '~'.$word.'[^\n]{0,40}?\bnr\.?\s*:?\s*([a-z0-9][a-z0-9\-\./]{0,24})~u' => 0.92,
            // Un cod de procedură, cum stă în sistemele de calitate: PO-HR-05.
            '~\bcod(?:ul)?\s*:?\s*((?:po|pl|pg|ps|is|pr)[\-\.][a-z0-9\-\.]{2,20})~u' => 0.9,
            '~\bnr\.?\s*:?\s*([a-z0-9][a-z0-9\-\.]{0,24})\s*/\s*\d{1,2}[.\-/\s]~u' => 0.88,
            '~\bnr\.?\s*:?\s*([0-9]{1,6})\b~u' => 0.8,
        ];

        foreach ($patterns as $pattern => $confidence) {
            if (preg_match($pattern, $flat, $m)) {
                $number = trim($m[1], ' /.-');

                if ($number !== '' && mb_strlen($number) <= 25) {
                    return $this->field(mb_strtoupper($number), $confidence, 'tipar: număr');
                }
            }
        }

        return null;
    }

    /**
     * Data hârtiei: „nr. 12 din 03.02.2026”, „București, 3 februarie 2026”.
     *
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function issued(string $flat): ?array
    {
        $patterns = [
            '~\bnr\.?\s*:?\s*[a-z0-9\-\./]{1,25}\s*(?:/|\bdin\b)\s*(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{2,4})~u' => 0.92,
            '~\bdin\s+data\s+de\s+(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{2,4})~u' => 0.9,
            '~\bdin\s+(\d{1,2}\s+[a-z]{3,10}\s+\d{4})~u' => 0.88,
            '~(\d{1,2}\s+(?:ianuarie|februarie|martie|aprilie|mai|iunie|iulie|august|septembrie|octombrie|noiembrie|decembrie)\s+\d{4})~u' => 0.85,
            '~(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{4})~u' => 0.7,
        ];

        foreach ($patterns as $pattern => $confidence) {
            if (preg_match($pattern, $flat, $m)) {
                $date = $this->date($m[1]);

                if ($date !== null) {
                    return $this->field($date, $confidence, 'tipar: dată');
                }
            }
        }

        return null;
    }

    /**
     * De când se aplică: „intră în vigoare la data de…”, „începând cu…”.
     *
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function effective(string $flat): ?array
    {
        $patterns = [
            '~(?:intra|intră)\s+in\s+vigoare[^\n]{0,40}?(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{2,4})~u' => 0.9,
            '~(?:incepand|începând)\s+cu\s+(?:data\s+de\s+)?(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{2,4})~u' => 0.88,
            '~se\s+aplica\s+(?:de\s+la\s+|incepand\s+cu\s+)?(?:data\s+de\s+)?(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{2,4})~u' => 0.85,
            '~(?:intra|intră)\s+in\s+vigoare[^\n]{0,40}?(\d{1,2}\s+[a-z]{3,10}\s+\d{4})~u' => 0.85,
        ];

        foreach ($patterns as $pattern => $confidence) {
            if (preg_match($pattern, $flat, $m)) {
                $date = $this->date($m[1]);

                if ($date !== null) {
                    return $this->field($date, $confidence, 'tipar: intrare în vigoare');
                }
            }
        }

        return null;
    }

    /**
     * Titlul: primul rând care spune ceva, de sus.
     *
     * Rândurile de antet („S.C. … S.R.L.”, „CUI …”) se sar: ele spun cine
     * scrie, nu ce scrie.
     *
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function title(string $text, string $head): ?array
    {
        foreach (preg_split('~\r?\n~', $head) ?: [] as $line) {
            $line = trim((string) preg_replace('~\s+~u', ' ', $line));
            $flat = $this->fold($line);

            if (mb_strlen($line) < 6 || mb_strlen($line) > 200) {
                continue;
            }

            // Antetul firmei și datele ei de identificare nu sunt titlu.
            if (preg_match('~\b(s\.?c\.?|s\.?r\.?l\.?|s\.?a\.?|cui|cif|reg\.?\s*com|j\d{2}/|telefon|email|adresa|sediul)\b~u', $flat)) {
                continue;
            }

            if (preg_match('~\b(decizi|procedur|hotarar|nota|regulament|instructiun)~u', $flat)) {
                return $this->field($line, 0.8, 'tipar: titlu');
            }
        }

        return null;
    }

    /**
     * Obiectul: ce urmează după „privind”, adică despre ce e hârtia.
     *
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function object(string $text): ?array
    {
        // `s`, fiindcă fraza se rupe pe două rânduri în orice document: fără
        // el, obiectul s-ar opri la prima întoarcere de linie.
        if (preg_match('~privind\s+(.{10,400}?)(?:\n\s*\n|\.\s|\z)~uis', $text, $m)) {
            $object = trim((string) preg_replace('~\s+~u', ' ', $m[1]));

            if ($object !== '') {
                return $this->field($object, 0.82, 'tipar: „privind…”');
            }
        }

        // Procedurile își scriu scopul, nu obiectul.
        if (preg_match('~(?:scopul|obiectul)\s+(?:prezentei\s+)?(?:proceduri|decizii)?[:\s]+(.{10,400}?)(?:\n\s*\n|\.\s|\z)~uis', $text, $m)) {
            $object = trim((string) preg_replace('~\s+~u', ' ', $m[1]));

            if ($object !== '') {
                return $this->field($object, 0.78, 'tipar: scop');
            }
        }

        return null;
    }

    /**
     * Cine a emis-o: funcția celui care semnează.
     *
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function issuer(string $flat): ?array
    {
        if (preg_match('~(?:emis|emisa|aprobat|aprobata)\s+de\s*:?\s*([a-z][a-z\s\-]{3,60})~u', $flat, $m)) {
            return $this->field($this->titleCase(trim($m[1])), 0.86, 'tipar: emitent');
        }

        foreach (self::ROLES as $role) {
            if (preg_match('~\b(?:in\s+calitate\s+de\s+)?('.preg_quote($role, '~').')\b~u', $flat, $m)) {
                return $this->field($this->titleCase($m[1]), 0.8, 'tipar: funcție semnatar');
            }
        }

        return null;
    }

    /** Data, scrisă în toate felurile în care o scriu oamenii. */
    private function date(string $raw): ?string
    {
        $raw = trim(mb_strtolower($raw));

        if (preg_match('~^(\d{1,2})\s+([a-z]{3,10})\s+(\d{4})$~u', $raw, $m)) {
            $month = self::MONTHS[$m[2]] ?? 0;

            return $this->build((int) $m[3], $month, (int) $m[1]);
        }

        $parts = preg_split('~[.\-/]~', $raw) ?: [];

        if (count($parts) !== 3) {
            return null;
        }

        [$day, $month, $year] = array_map('intval', $parts);

        return $this->build($year < 100 ? 2000 + $year : $year, $month, $day);
    }

    private function build(int $year, int $month, int $day): ?string
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $year < 1990 || $year > 2100) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)?->toDateString();
    }

    /** „director general” → „Director General”. */
    private function titleCase(string $value): string
    {
        return mb_convert_case(trim((string) preg_replace('~\s+~u', ' ', $value)), MB_CASE_TITLE, 'UTF-8');
    }

    /** Textul fără diacritice și fără spații de prisos, ca să se potrivească tiparele. */
    private function fold(string $value): string
    {
        return (string) preg_replace('~[ \t]+~u', ' ', strtr(mb_strtolower($value), [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]));
    }

    /**
     * @return array{value: mixed, confidence: float, source: ?string}
     */
    private function field(mixed $value, float $confidence, ?string $source): array
    {
        return ['value' => $value, 'confidence' => $confidence, 'source' => $source];
    }
}
