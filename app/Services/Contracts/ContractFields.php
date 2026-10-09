<?php

namespace App\Services\Contracts;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Ce se poate citi dintr-un contract românesc, fără să întrebe pe nimeni.
 *
 * Nu e magie și nu trebuie crezută pe cuvânt: fiecare câmp vine cu gradul lui
 * de încredere și cu bucata de text din care a ieșit, iar omul îl poate
 * suprascrie. Ce iese sub 85% se arată galben pe ecran și cere o privire.
 *
 * Textul scanat vine adesea fără diacritice și cu litere stricate, așa că
 * tiparele se caută pe un text „turtit” — litere mici, fără diacritice — dar
 * valoarea se taie din textul original.
 */
class ContractFields
{
    /** Sub atât, câmpul se arată ca nesigur și cere confirmare. */
    public const SURE = 0.85;

    /**
     * @param  list<string>  $houses  numele companiilor noastre, ca să nu le ia drept partener
     * @return array<string, array{value: mixed, confidence: float, source: ?string}>
     */
    public function extract(string $text, array $houses = []): array
    {
        $text = (string) preg_replace('~[ \t]+~u', ' ', $text);
        $flat = $this->fold($text);

        $fields = array_filter([
            'number' => $this->number($text, $flat),
            'signed_at' => $this->signedAt($text, $flat),
            'expires_at' => $this->expiresAt($text, $flat),
            'notice_days' => $this->noticeDays($flat),
            'payment_terms' => $this->paymentTerms($text, $flat),
            'value' => $this->value($text, $flat),
            'partner_name' => $this->partner($text, $houses),
            'partner_tax_id' => $this->taxId($text, $flat),
            'object' => $this->object($text, $flat),
            'governing_law' => $this->law($flat),
            'auto_renew' => $this->autoRenew($flat),
        ], fn (?array $field) => $field !== null);

        // Durata („12 luni de la semnare”) e a doua cale către scadență: se
        // folosește numai dacă n-a scris nicăieri o dată anume.
        if (! isset($fields['expires_at']) && isset($fields['signed_at'])) {
            $duration = $this->duration($flat, $fields['signed_at']['value']);

            if ($duration !== null) {
                $fields['expires_at'] = $duration;
            }
        }

        return $fields;
    }

    /**
     * Câmpurile care au nevoie de ochi: cele sub pragul de încredere.
     *
     * @param  array<string, array{value: mixed, confidence: float, source: ?string}>  $fields
     * @return list<string>
     */
    public static function unsure(array $fields): array
    {
        return array_keys(array_filter($fields, fn (array $field) => $field['confidence'] < self::SURE));
    }

    /**
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function number(string $text, string $flat): ?array
    {
        if (preg_match('~contract[^\n]{0,60}?nr\.?\s*:?\s*([a-z0-9][a-z0-9\-\./]{0,24})~u', $flat, $m, PREG_OFFSET_CAPTURE)) {
            return $this->hit($text, $m[1], 0.9);
        }

        if (preg_match('~\bnr\.?\s*:?\s*([0-9][0-9\-\./]{0,20})\s*/\s*\d{1,2}[.\-/]\d{1,2}[.\-/]\d{2,4}~u', $flat, $m, PREG_OFFSET_CAPTURE)) {
            return $this->hit($text, $m[1], 0.78);
        }

        return null;
    }

    /**
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function signedAt(string $text, string $flat): ?array
    {
        $patterns = [
            '~incheiat(?:\s+astazi|\s+la\s+data\s+de|\s+in\s+data\s+de)?\s*:?\s*(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{2,4})~u' => 0.93,
            '~data\s+(?:semnarii|incheierii)\s*:?\s*(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{2,4})~u' => 0.92,
            '~nr\.?\s*[a-z0-9\-\./]{1,24}\s*/\s*(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{2,4})~u' => 0.86,
        ];

        foreach ($patterns as $pattern => $confidence) {
            if (preg_match($pattern, $flat, $m, PREG_OFFSET_CAPTURE)) {
                $date = $this->date($m[1][0]);

                if ($date !== null) {
                    return ['value' => $date, 'confidence' => $confidence, 'source' => $this->around($text, (int) $m[1][1])];
                }
            }
        }

        return null;
    }

    /**
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function expiresAt(string $text, string $flat): ?array
    {
        $patterns = [
            '~(?:valabil|valabilitate|produce\s+efecte|isi\s+produce\s+efectele)[^.\n]{0,60}?pana\s+la\s+(?:data\s+de\s+)?(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{2,4})~u' => 0.93,
            '~(?:expira|inceteaza)[^.\n]{0,40}?(?:la|in)\s+(?:data\s+de\s+)?(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{2,4})~u' => 0.9,
            '~pana\s+la\s+data\s+de\s+(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{2,4})~u' => 0.8,
        ];

        foreach ($patterns as $pattern => $confidence) {
            if (preg_match($pattern, $flat, $m, PREG_OFFSET_CAPTURE)) {
                $date = $this->date($m[1][0]);

                if ($date !== null) {
                    return ['value' => $date, 'confidence' => $confidence, 'source' => $this->around($text, (int) $m[1][1])];
                }
            }
        }

        return null;
    }

    /**
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function duration(string $flat, string $signed): ?array
    {
        if (! preg_match('~durat[ae][^.\n]{0,40}?(\d{1,3})\s*(luni|ani|zile)~u', $flat, $m)) {
            return null;
        }

        try {
            $start = CarbonImmutable::parse($signed);
        } catch (Throwable) {
            return null;
        }

        $count = (int) $m[1];
        $end = match ($m[2]) {
            'ani' => $start->addYears($count),
            'zile' => $start->addDays($count),
            default => $start->addMonths($count),
        };

        return ['value' => $end->subDay()->toDateString(), 'confidence' => 0.7, 'source' => trim($m[0])];
    }

    /**
     * @return array{value: int, confidence: float, source: ?string}|null
     */
    private function noticeDays(string $flat): ?array
    {
        if (! preg_match('~preaviz[^.\n]{0,30}?(\d{1,3})\s*(zile|luni)~u', $flat, $m)) {
            return null;
        }

        $days = (int) $m[1] * ($m[2] === 'luni' ? 30 : 1);

        return ['value' => $days, 'confidence' => 0.84, 'source' => trim($m[0])];
    }

    /**
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function paymentTerms(string $text, string $flat): ?array
    {
        if (preg_match('~(\d{1,3})\s*(?:de\s+)?zile[^.\n]{0,40}?factur~u', $flat, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'value' => (int) $m[1][0].' zile de la factură',
                'confidence' => 0.8,
                'source' => $this->around($text, (int) $m[0][1]),
            ];
        }

        return null;
    }

    /**
     * @return array{value: array{amount: float, currency: string}, confidence: float, source: ?string}|null
     */
    private function value(string $text, string $flat): ?array
    {
        $money = '([0-9][0-9\. ]{0,15}(?:,\d{1,2})?)\s*(eur|euro|ron|lei|usd|chf)';

        foreach (['~valoar[ea][^.\n]{0,80}?'.$money.'~u' => 0.9, '~pret[^.\n]{0,60}?'.$money.'~u' => 0.72] as $pattern => $confidence) {
            if (preg_match($pattern, $flat, $m, PREG_OFFSET_CAPTURE)) {
                $amount = (float) str_replace([' ', '.', ','], ['', '', '.'], $m[1][0]);

                if ($amount <= 0) {
                    continue;
                }

                return [
                    'value' => ['amount' => round($amount, 2), 'currency' => $this->currency($m[2][0])],
                    'confidence' => $confidence,
                    'source' => $this->around($text, (int) $m[0][1]),
                ];
            }
        }

        return null;
    }

    /**
     * Partenerul: societatea din text care nu e una dintre companiile noastre.
     *
     * @param  list<string>  $houses
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function partner(string $text, array $houses): ?array
    {
        if (! preg_match_all('~\b([A-ZȘȚĂÂÎ][A-ZȘȚĂÂÎ0-9&\.\- ]{2,60}?(?:S\.?R\.?L|S\.?A|GMBH|LTD|LIMITED|B\.?V|INC|LLC)\.?)\b~u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $ours = array_map(fn (string $name) => $this->fold($name), $houses);

        foreach ($matches[1] as $match) {
            $name = trim((string) preg_replace('~\s+~u', ' ', $match[0]));
            $folded = $this->fold($name);

            if ($folded === '' || array_any($ours, fn (string $house) => $house !== '' && str_contains($folded, $house))) {
                continue;
            }

            return ['value' => $name, 'confidence' => 0.86, 'source' => $this->around($text, (int) $match[1])];
        }

        return null;
    }

    /**
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function taxId(string $text, string $flat): ?array
    {
        if (preg_match('~(?:c\.?u\.?i\.?|cod\s+unic|cod\s+fiscal|c\.?i\.?f\.?)\s*:?\s*(ro)?\s*(\d{2,10})~u', $flat, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'value' => mb_strtoupper(($m[1][0] !== '' ? 'RO' : '').$m[2][0]),
                'confidence' => 0.88,
                'source' => $this->around($text, (int) $m[0][1]),
            ];
        }

        return null;
    }

    /**
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function object(string $text, string $flat): ?array
    {
        if (! preg_match('~obiectul\s+(?:prezentului\s+)?contract(?:ului)?[^a-z0-9]{0,30}~u', $flat, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $from = (int) $m[0][1] + mb_strlen($m[0][0]);
        $slice = trim(mb_substr($text, $from, 400));
        $slice = (string) preg_replace('~\s+~u', ' ', $slice);
        $end = mb_strpos($slice, '. ');
        $value = trim($end !== false ? mb_substr($slice, 0, $end + 1) : $slice);

        return $value === '' ? null : ['value' => mb_substr($value, 0, 300), 'confidence' => 0.8, 'source' => $value];
    }

    /**
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function law(string $flat): ?array
    {
        if (preg_match('~leg(?:ea|islatia)\s+(romana|romaniei)~u', $flat, $m)) {
            return ['value' => 'română', 'confidence' => 0.9, 'source' => trim($m[0])];
        }

        return null;
    }

    /**
     * @return array{value: bool, confidence: float, source: ?string}|null
     */
    private function autoRenew(string $flat): ?array
    {
        if (preg_match('~(tacita\s+relocatiune|reinnoire\s+tacita|se\s+prelungeste\s+(?:automat|de\s+drept))~u', $flat, $m)) {
            return ['value' => true, 'confidence' => 0.82, 'source' => trim($m[0])];
        }

        return null;
    }

    private function currency(string $raw): string
    {
        return match (mb_strtolower($raw)) {
            'eur', 'euro' => 'EUR',
            'usd' => 'USD',
            'chf' => 'CHF',
            default => 'RON',
        };
    }

    private function date(string $raw): ?string
    {
        $parts = preg_split('~[.\-/]~', $raw) ?: [];

        if (count($parts) !== 3) {
            return null;
        }

        [$day, $month, $year] = array_map('intval', $parts);
        $year = $year < 100 ? 2000 + $year : $year;

        if ($day < 1 || $day > 31 || $month < 1 || $month > 12 || $year < 1990 || $year > 2100) {
            return null;
        }

        try {
            return CarbonImmutable::create($year, $month, $day)?->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array{0: string, 1: int}  $match
     * @return array{value: string, confidence: float, source: ?string}
     */
    private function hit(string $text, array $match, float $confidence): array
    {
        return ['value' => trim($match[0]), 'confidence' => $confidence, 'source' => $this->around($text, (int) $match[1])];
    }

    /** Bucata de text din jurul potrivirii: omul vede de unde a ieșit cifra. */
    private function around(string $text, int $offset, int $span = 90): string
    {
        $start = max(0, $offset - 30);

        return trim((string) preg_replace('~\s+~u', ' ', mb_substr($text, $start, $span)));
    }

    private function fold(string $value): string
    {
        return (string) preg_replace('~\s+~u', ' ', strtr(mb_strtolower($value), [
            'ă' => 'a', 'â' => 'a', 'à' => 'a', 'î' => 'i', 'ì' => 'i',
            'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'ö' => 'o', 'ü' => 'u',
        ]));
    }
}
