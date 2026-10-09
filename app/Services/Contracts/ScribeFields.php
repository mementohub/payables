<?php

namespace App\Services\Contracts;

use App\Ai\Agents\ContractScribe;
use App\Services\Ai\Meter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Câmpurile fișei, citite de agent în loc de tipare.
 *
 * Întoarce aceeași formă ca {@see ContractFields}, ca să se poată pune una
 * peste alta: nume de câmp => ['value' => .., 'confidence' => .., 'source' => ..].
 * Dacă agentul tace sau dă ceva de neînțeles, întoarce un vraf gol și
 * rămâne ce-au găsit tiparele.
 */
class ScribeFields
{
    /**
     * Cât din contract vede agentul.
     *
     * Datele stau la cele două capete: partenerul și obiectul în primele
     * pagini, durata și semnăturile în ultimele. Dacă documentul e lung, i se
     * dau amândouă capetele și i se spune limpede că mijlocul lipsește.
     */
    private const HEAD = 30000;

    private const TAIL = 12000;

    /**
     * Cât loc are fiecare câmp în fișă. Peste atât, baza refuză rândul
     * întreg — și cu el se pierde și ce-a citit bine agentul.
     */
    private const ROOM = [
        'partner_name' => 200,
        'partner_tax_id' => 40,
        'number' => 40,
        'object' => 5000,
        'payment_terms' => 200,
        'governing_law' => 80,
    ];

    public function __construct(private Meter $meter) {}

    public function on(): bool
    {
        return (bool) config('contracts.ai.enabled', false)
            && (string) config('ai.providers.openai.key', '') !== '';
    }

    /**
     * @param  list<string>  $houses
     * @return array<string, array{value: mixed, confidence: float, source: ?string}>
     */
    public function extract(string $text, array $houses = [], ?int $contractId = null): array
    {
        if (! $this->on() || trim($text) === '') {
            return [];
        }

        try {
            $response = ContractScribe::make()->prompt($this->prompt($text, $houses));
        } catch (Throwable $e) {
            Log::warning('Citirea cu agent n-a mers: '.$e->getMessage());

            return [];
        }

        $this->meter->record(ContractScribe::class, $response, $contractId);

        return $this->fields((array) $response->structured);
    }

    /**
     * @param  array<string, mixed>  $said
     * @return array<string, array{value: mixed, confidence: float, source: ?string}>
     */
    private function fields(array $said): array
    {
        $fields = [];

        foreach (self::ROOM as $name => $room) {
            $field = $this->text($said[$name] ?? null);

            if ($field !== null) {
                // Agentul mai scapă o frază întreagă acolo unde fișa are loc
                // de un rând. Se taie la ultimul cuvânt întreg, ca să nu cadă
                // salvarea pe un câmp prea lung.
                $field['value'] = $this->short($field['value'], $room);
                $fields[$name] = $field;
            }
        }

        // Codul fiscal se scrie în contracte cu spații și puncte după cum s-a
        // nimerit („RO 39404632”); în fișă intră strâns, ca să se potrivească
        // cu fișa partenerului. Dacă nu seamănă a cod fiscal, nu e.
        if (isset($fields['partner_tax_id'])) {
            $tax = (string) preg_replace('~[^A-Z0-9]~', '', strtoupper($fields['partner_tax_id']['value']));

            if (preg_match('~^[A-Z]{0,3}\d{2,15}$~', $tax) === 1) {
                $fields['partner_tax_id']['value'] = $tax;
            } else {
                unset($fields['partner_tax_id']);
            }
        }

        foreach (['signed_at', 'expires_at'] as $name) {
            $field = $this->date($said[$name] ?? null);

            if ($field !== null) {
                $fields[$name] = $field;
            }
        }

        $notice = $this->whole($said['notice_days'] ?? null);

        if ($notice !== null) {
            $fields['notice_days'] = $notice;
        }

        if (($said['auto_renew']['value'] ?? null) === true) {
            $fields['auto_renew'] = [
                'value' => true,
                'confidence' => $this->sure($said['auto_renew']),
                'source' => $said['auto_renew']['quote'] ?? null,
            ];
        }

        $amount = $said['value_amount']['value'] ?? null;
        $currency = strtoupper(trim((string) ($said['value_currency']['value'] ?? '')));

        if (is_numeric($amount) && (float) $amount > 0 && preg_match('~^[A-Z]{3}$~', $currency) === 1) {
            $fields['value'] = [
                'value' => ['amount' => round((float) $amount, 2), 'currency' => $currency],
                'confidence' => $this->sure($said['value_amount']),
                'source' => $said['value_amount']['quote'] ?? null,
            ];
        }

        return $fields;
    }

    /** @return array{value: string, confidence: float, source: ?string}|null */
    private function text(mixed $field): ?array
    {
        $value = trim((string) ($field['value'] ?? ''));

        return $value === '' ? null : [
            'value' => $value,
            'confidence' => $this->sure($field),
            'source' => $field['quote'] ?? null,
        ];
    }

    /** @return array{value: string, confidence: float, source: ?string}|null */
    private function date(mixed $field): ?array
    {
        $value = trim((string) ($field['value'] ?? ''));

        if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $value) !== 1) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        // Un contract semnat acum o sută de ani sau peste cincizeci e o
        // greșeală de citire, nu o informație.
        if ($date->year < 1990 || $date->year > (int) now()->addYears(50)->year) {
            return null;
        }

        return [
            'value' => $date->toDateString(),
            'confidence' => $this->sure($field),
            'source' => $field['quote'] ?? null,
        ];
    }

    /** @return array{value: int, confidence: float, source: ?string}|null */
    private function whole(mixed $field): ?array
    {
        $value = $field['value'] ?? null;

        if (! is_numeric($value) || (int) $value < 0 || (int) $value > 3650) {
            return null;
        }

        return [
            'value' => (int) $value,
            'confidence' => $this->sure($field),
            'source' => $field['quote'] ?? null,
        ];
    }

    /** Taie la ultimul cuvânt întreg care încape. */
    private function short(string $value, int $room): string
    {
        if (mb_strlen($value) <= $room) {
            return $value;
        }

        $cut = mb_substr($value, 0, $room);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $room / 2 ? mb_substr($cut, 0, $space) : $cut, " \t,;:-");
    }

    private function sure(mixed $field): float
    {
        $value = (float) ($field['confidence'] ?? 0.8);

        return round(max(0.0, min(1.0, $value)), 2);
    }

    /** @param  list<string>  $houses */
    private function prompt(string $text, array $houses): string
    {
        $ours = array_values(array_unique(array_filter(array_map('trim', $houses))));

        $body = mb_strlen($text) <= self::HEAD + self::TAIL
            ? $text
            : mb_substr($text, 0, self::HEAD)
                ."\n\n[... mijlocul documentului lipsește ...]\n\n"
                .mb_substr($text, -self::TAIL);

        return "Casele noastre (nu sunt niciodată „partener”):\n- ".implode("\n- ", $ours)."\n\n"
            ."=== TEXTUL CONTRACTULUI ===\n"
            .$body."\n"
            .'=== SFÂRȘITUL TEXTULUI ===';
    }
}
