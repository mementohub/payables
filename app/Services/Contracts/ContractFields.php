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

    /** Cuvintele după care se cunoaște limba contractului. */
    private const MARKERS = [
        'ro' => ['contract', 'partile', 'prezentul', 'obiectul', 'valoarea', 'scadenta', 'incheiat', 'preaviz', 'furnizor'],
        'en' => ['agreement', 'parties', 'hereby', 'whereas', 'shall', 'herein', 'notice', 'supplier', 'between'],
    ];

    /** Lunile scrise în litere, în amândouă limbile. */
    private const MONTHS = [
        'ianuarie' => 1, 'februarie' => 2, 'martie' => 3, 'aprilie' => 4, 'mai' => 5, 'iunie' => 6,
        'iulie' => 7, 'august' => 8, 'septembrie' => 9, 'octombrie' => 10, 'noiembrie' => 11, 'decembrie' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7,
        'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * În ce limbă e scris contractul.
     *
     * Nu ca să aleagă un singur set de tipare — un contract românesc poate
     * avea clauze în engleză și invers — ci ca să știe pe care să-l creadă mai
     * mult când amândouă găsesc ceva.
     */
    public function language(string $text): string
    {
        $flat = $this->fold($text);
        $score = [];

        foreach (self::MARKERS as $language => $words) {
            $score[$language] = array_sum(array_map(
                fn (string $word) => preg_match_all('~\b'.$word.'~u', $flat),
                $words,
            ));
        }

        return ($score['en'] ?? 0) > ($score['ro'] ?? 0) ? 'en' : 'ro';
    }

    /**
     * @param  list<string>  $houses  numele companiilor noastre, ca să nu le ia drept partener
     * @return array<string, array{value: mixed, confidence: float, source: ?string}>
     */
    public function extract(string $text, array $houses = []): array
    {
        $text = (string) preg_replace('~[ \t]+~u', ' ', $text);
        $flat = $this->fold($text);
        $language = $this->language($text);

        $fields = array_filter([
            'number' => $this->number($text, $flat),
            'signed_at' => $this->signedAt($text, $flat),
            'expires_at' => $this->expiresAt($text, $flat),
            'notice_days' => $this->noticeDays($flat),
            'payment_terms' => $this->paymentTerms($text, $flat),
            'value' => $this->value($text, $flat),
            'partner_name' => $partner = $this->partner($text, $houses),
            'partner_tax_id' => $this->taxId($text, $flat, $partner['offset'] ?? null),
            'object' => $this->object($text, $flat),
            'governing_law' => $this->law($flat),
            'auto_renew' => $this->autoRenew($flat),
        ], fn (?array $field) => $field !== null);

        $at = $fields['partner_name']['offset'] ?? null;

        // Poziția numelui a folosit la găsirea codului fiscal; mai departe
        // n-are ce căuta.
        unset($fields['partner_name']['offset']);

        // Contractul în engleză: aceleași câmpuri, alte tipare. Se caută numai
        // ce n-a găsit setul românesc, iar dacă documentul chiar e în engleză,
        // ce iese de aici bate ce-a nimerit celălalt set din întâmplare.
        foreach ($this->english($text, $flat, $at) as $name => $field) {
            $found = $fields[$name] ?? null;

            if ($found === null || ($language === 'en' && $field['confidence'] >= $found['confidence'])) {
                $fields[$name] = $field;
            }
        }

        $fields['language'] = ['value' => $language, 'confidence' => 1.0, 'source' => null];

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
     * Același contract, citit englezește.
     *
     * Tiparele sunt altele, dar socoteala e aceeași: fiecare câmp cu gradul
     * lui de încredere și cu bucata de text din care a ieșit. Cifrele se scriu
     * invers decât la noi („18,400,000.00”), iar datele vin și cu luna în
     * litere, așa că amândouă se recunosc după felul lor.
     *
     * @return array<string, array{value: mixed, confidence: float, source: ?string}>
     */
    private function english(string $text, string $flat, ?int $after = null): array
    {
        $date = '(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{2,4}|\d{1,2}(?:st|nd|rd|th)?\s+(?:day\s+of\s+)?[a-z]{3,9},?\s+\d{4}|[a-z]{3,9}\s+\d{1,2},?\s+\d{4})';
        $found = [];

        $take = function (string $name, string $pattern, float $confidence, callable $make) use ($flat, $text, &$found): void {
            if (isset($found[$name]) || ! preg_match($pattern, $flat, $m, PREG_OFFSET_CAPTURE)) {
                return;
            }

            $value = $make(array_map(fn (array $part) => $part[0], $m));

            if ($value !== null) {
                $found[$name] = ['value' => $value, 'confidence' => $confidence, 'source' => $this->around($text, $this->at($flat, (int) $m[0][1]))];
            }
        };

        // Numărul se ia din textul adevărat, nu din cel turtit: „MA-2026-147”
        // scris cu litere mici ar arăta ca altceva.
        if (preg_match('~(?:agreement|contract)[^\\n]{0,40}?(?:no|nr|number)\\.?\\s*:?\\s*([A-Za-z0-9][A-Za-z0-9\\-\\./]{0,24})~ui', $text, $m, PREG_OFFSET_CAPTURE)) {
            $found['number'] = ['value' => trim($m[1][0]), 'confidence' => 0.88, 'source' => $this->around($text, $this->at($text, (int) $m[0][1]))];
        }

        foreach ([
            '~(?:dated|made|entered\s+into|executed)(?:\s+as\s+of|\s+on|\s+this)?\s*:?\s*'.$date.'~u' => 0.92,
            '~effective\s+(?:as\s+of\s+|date\s*:?\s*)'.$date.'~u' => 0.9,
            '~signature\s+date\s*:?\s*'.$date.'~u' => 0.88,
        ] as $pattern => $confidence) {
            $take('signed_at', $pattern, $confidence, fn (array $m) => $this->anyDate($m[1], 'en'));
        }

        foreach ([
            '~(?:expires?|expiry|terminates?|termination\s+date)[^.
]{0,30}?(?:on|date)?\s*:?\s*'.$date.'~u' => 0.9,
            '~(?:valid|in\s+force|continue|remain\s+in\s+effect)[^.
]{0,40}?(?:until|through|to)\s+'.$date.'~u' => 0.9,
        ] as $pattern => $confidence) {
            $take('expires_at', $pattern, $confidence, fn (array $m) => $this->anyDate($m[1], 'en'));
        }

        $take('notice_days', '~(\d{1,3})\s*(days?|months?)[^.
]{0,30}?(?:prior\s+)?(?:written\s+)?notice~u', 0.84,
            fn (array $m) => (int) $m[1] * (str_starts_with($m[2], 'month') ? 30 : 1));

        $take('notice_days', '~notice\s+(?:period\s+)?of\s+(\d{1,3})\s*(days?|months?)~u', 0.84,
            fn (array $m) => (int) $m[1] * (str_starts_with($m[2], 'month') ? 30 : 1));

        $take('payment_terms', '~(?:within\s+)?(\d{1,3})\s*days?[^.
]{0,40}?invoice~u', 0.82, fn (array $m) => $m[1].' days from invoice');
        $take('payment_terms', '~net\s+(\d{1,3})~u', 0.74, fn (array $m) => 'net '.$m[1]);

        $money = '(?:(eur|usd|ron|gbp|chf|€|\$|£)\s*)?([0-9][0-9,\. ]{2,18})\s*(eur|usd|ron|gbp|chf|lei)?';

        foreach ([
            '~(?:total\s+)?(?:contract\s+)?(?:value|price|fee|amount)[^.
]{0,40}?(?:of|is|:)\s*'.$money.'~u' => 0.88,
            '~consideration[^.
]{0,40}?'.$money.'~u' => 0.72,
        ] as $pattern => $confidence) {
            $take('value', $pattern, $confidence, function (array $m) {
                $amount = $this->amount($m[2]);

                return $amount === null ? null : ['amount' => $amount, 'currency' => $this->currency($m[1] !== '' ? $m[1] : ($m[3] ?? ''))];
            });
        }

        // Codul fiscal al partenerului e cel scris după numele lui; primul din
        // document e al nostru.
        if (preg_match_all('~(?:vat|tax)\s*(?:id|no|number|reg)?\.?\s*:?\s*([a-z]{0,2}\s?\d[\d\s]{3,12})~u', $flat, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $match) {
                if ($after !== null && $this->at($flat, (int) $match[0][1]) < $after) {
                    continue;
                }

                $found['partner_tax_id'] = [
                    'value' => mb_strtoupper((string) preg_replace('~\s~', '', $match[1][0])),
                    'confidence' => $after === null ? 0.72 : 0.86,
                    'source' => $this->around($text, $this->at($flat, (int) $match[0][1])),
                ];

                break;
            }
        }

        $take('governing_law', '~governed\s+by[^.
]{0,40}?laws?\s+of\s+([a-z][a-z\s]{2,40}?)(?:\.|,|;|$)~u', 0.88, fn (array $m) => trim($m[1]));

        $take('auto_renew', '~(automatically\s+renew|auto-?renew|successive\s+(?:terms|periods)|evergreen)~u', 0.84, fn () => true);

        if (preg_match('~(?:subject(?:\s+matter)?|scope)\s+of\s+(?:this\s+|the\s+)?(?:agreement|contract|services)[^a-z0-9]{0,30}~u', $flat, $m, PREG_OFFSET_CAPTURE)) {
            $from = $this->at($flat, (int) $m[0][1]) + mb_strlen($m[0][0]);
            $slice = trim((string) preg_replace('~\s+~u', ' ', mb_substr($text, $from, 400)));
            $end = mb_strpos($slice, '. ');
            $value = trim($end !== false ? mb_substr($slice, 0, $end + 1) : $slice);

            if ($value !== '') {
                $found['object'] = ['value' => mb_substr($value, 0, 300), 'confidence' => 0.8, 'source' => $value];
            }
        }

        return $found;
    }

    /**
     * O dată scrisă oricum: cu cifre sau cu luna în litere, la noi sau la ei.
     *
     * Între „12/02/2026” englezesc și cel românesc nu se poate alege cu
     * certitudine — unul spune februarie, celălalt decembrie. Se merge pe
     * obiceiul limbii, iar când ziua trece de 12 nu mai e nicio îndoială.
     */
    private function anyDate(string $raw, string $language): ?string
    {
        $raw = trim(mb_strtolower($raw));

        if (preg_match('~^(\d{1,2})(?:st|nd|rd|th)?\s+(?:day\s+of\s+)?([a-z]{3,9}),?\s+(\d{4})$~u', $raw, $m)) {
            return $this->build((int) $m[3], self::MONTHS[$m[2]] ?? 0, (int) $m[1]);
        }

        if (preg_match('~^([a-z]{3,9})\s+(\d{1,2}),?\s+(\d{4})$~u', $raw, $m)) {
            return $this->build((int) $m[3], self::MONTHS[$m[1]] ?? 0, (int) $m[2]);
        }

        $parts = preg_split('~[.\-/]~', $raw) ?: [];

        if (count($parts) !== 3) {
            return null;
        }

        [$first, $second, $year] = array_map('intval', $parts);

        // Engleza pune luna întâi, dar numai dacă poate fi lună.
        [$day, $month] = $language === 'en' && $first <= 12 ? [$second, $first] : [$first, $second];

        return $this->build($year < 100 ? 2000 + $year : $year, $month, $day);
    }

    /** O sumă scrisă în felul lor („18,400,000.00”) sau în al nostru. */
    private function amount(string $raw): ?float
    {
        $raw = trim((string) preg_replace('~\s~', '', $raw));
        $comma = mb_strrpos($raw, ',');
        $dot = mb_strrpos($raw, '.');

        // Ultimul semn dintre cele două e separatorul de zecimale.
        $clean = match (true) {
            $comma !== false && $dot !== false => $comma > $dot
                ? str_replace([',', '.'], ['.', ''], $raw)
                : str_replace(',', '', $raw),
            $comma !== false => preg_match('~,\d{1,2}$~', $raw) ? str_replace(',', '.', $raw) : str_replace(',', '', $raw),
            default => preg_match('~\.\d{3}$~', $raw) ? str_replace('.', '', $raw) : $raw,
        };

        $amount = (float) $clean;

        return $amount > 0 ? round($amount, 2) : null;
    }

    private function build(int $year, int $month, int $day): ?string
    {
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
                    return ['value' => $date, 'confidence' => $confidence, 'source' => $this->around($text, $this->at($flat, (int) $m[1][1]))];
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
                    return ['value' => $date, 'confidence' => $confidence, 'source' => $this->around($text, $this->at($flat, (int) $m[1][1]))];
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
                'source' => $this->around($text, $this->at($flat, (int) $m[0][1])),
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
                    'source' => $this->around($text, $this->at($flat, (int) $m[0][1])),
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

            $at = $this->at($text, (int) $match[1]);

            return ['value' => $name, 'confidence' => 0.86, 'source' => $this->around($text, $at), 'offset' => $at];
        }

        return null;
    }

    /**
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function taxId(string $text, string $flat, ?int $after = null): ?array
    {
        $pattern = '~(?:c\.?u\.?i\.?|cod\s+unic|cod\s+fiscal|c\.?i\.?f\.?)\s*:?\s*(ro)?\s*(\d{2,10})~u';

        if (! preg_match_all($pattern, $flat, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $read = fn (array $match, float $confidence) => [
            'value' => mb_strtoupper(($match[1][0] !== '' ? 'RO' : '').$match[2][0]),
            'confidence' => $confidence,
            'source' => $this->around($text, $this->at($flat, (int) $match[0][1])),
        ];

        // Într-un contract sunt cel puțin două coduri fiscale: al nostru, în
        // antet, și al partenerului, lângă numele lui. Îl vrem pe al doilea.
        foreach ($all as $match) {
            if ($after !== null && $this->at($flat, (int) $match[0][1]) >= $after) {
                return $read($match, 0.88);
            }
        }

        return $read($all[0], $after === null ? 0.74 : 0.6);
    }

    /**
     * @return array{value: string, confidence: float, source: ?string}|null
     */
    private function object(string $text, string $flat): ?array
    {
        if (! preg_match('~obiectul\s+(?:prezentului\s+)?contract(?:ului)?[^a-z0-9]{0,30}~u', $flat, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $from = $this->at($flat, (int) $m[0][1]) + mb_strlen($m[0][0]);
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
        return match (mb_strtolower(trim($raw))) {
            'eur', 'euro', '€' => 'EUR',
            'usd', '$' => 'USD',
            'gbp', '£' => 'GBP',
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

    /**
     * Bucata de text din jurul potrivirii: omul vede de unde a ieșit cifra.
     *
     * Poziția e în caractere, nu în octeți — cine caută o aduce la caractere
     * cu `at()`, fiindcă el știe în ce text a căutat.
     */
    private function around(string $text, int $offset, int $span = 90): string
    {
        return trim((string) preg_replace('~\s+~u', ' ', mb_substr($text, max(0, $offset - 30), $span)));
    }

    /**
     * Textul „turtit”, pentru căutat: litere mici, fără diacritice.
     *
     * Nu se scurtează: fiecare caracter rămâne pe locul lui, ca o potrivire
     * găsită aici să arate spre aceeași bucată din textul adevărat.
     */
    /**
     * Poziția dată de preg e în octeți, în textul în care s-a căutat; ca să
     * taie din celălalt text trebuie întâi adusă la caractere. Un „ă” e doi
     * octeți și un singur caracter, iar textul turtit are „a” în locul lui —
     * aceleași caractere, alți octeți.
     */
    private function at(string $haystack, int $offset): int
    {
        return mb_strlen(substr($haystack, 0, max(0, $offset)));
    }

    private function fold(string $value): string
    {
        return (string) preg_replace('~\s~u', ' ', strtr(mb_strtolower($value), [
            'ă' => 'a', 'â' => 'a', 'à' => 'a', 'î' => 'i', 'ì' => 'i',
            'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'ö' => 'o', 'ü' => 'u',
        ]));
    }
}
