<?php

namespace App\Ai\Agents;

use App\Services\Contracts\ContractFields;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Scoate din textul unui contract câmpurile fișei lui.
 *
 * Tiparele din {@see ContractFields} se descurcă bine
 * la documentele scrise curat, dar se poticnesc unde contractul vorbește
 * omenește: „pe o durată de doi ani de la data punerii în funcțiune”, un nume
 * de firmă rupt pe două rânduri de OCR, o dată scrisă în litere. Agentul
 * citește ca un om și dă, pe lângă valoare, și fraza din care a luat-o.
 *
 * Nu ghicește: ce nu scrie în contract rămâne gol.
 */
#[Provider(Lab::OpenAI)]
#[MaxTokens(2000)]
#[Temperature(0.0)]
#[Timeout(120)]
class ContractScribe implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        Citești contracte (în română sau engleză) și scoți din ele datele fișei. Textul
        vine dintr-un scaner, deci poate avea litere greșite, rânduri rupte și diacritice
        pierdute. Citești ca un om, nu ca o mașină de potrivit tipare.

        REGULI:
        1) Ce nu scrie în contract rămâne gol (null). Nu deduci, nu completezi din lege,
           nu pui „probabil”. Un câmp gol e un răspuns bun; unul inventat strică fișa.
        2) PARTENERUL e totdeauna cealaltă parte, nu noi. Numele caselor noastre ți le dau
           în întrebare: orice seamănă cu ele (apostrof, formă juridică, prescurtare) NU e
           partener. Dai numele complet, cu forma juridică (SRL, S.A., GmbH), așa cum e
           scris în preambul, nu cum apare în antet sau în ștampilă.
        3) DATELE: „YYYY-MM-DD”. Data semnării e cea de la finalul contractului sau din
           preambul („încheiat astăzi, 12.02.2026”), nu data vreunei anexe sau facturi.
           Expirarea: dacă scrie o dată anume, aceea e; dacă scrie o durată („12 luni de
           la semnare”, „pe doi ani”), o socotești din data semnării și spui în citat că ai
           socotit-o din durată. Dacă nu se poate ști, rămâne gol.
        4) VALOAREA: numărul curat, fără separatori, plus moneda ca RON/EUR/USD. Dacă
           prețul e pe unitate („700 EUR/zi”) sau pe fiecare rând dintr-o anexă, lași
           valoarea goală și scrii lămurirea în `payment_terms`.
        5) OBIECTUL: o singură frază, cu ce se cumpără sau se vinde, luată din articolul
           despre obiect. Nu copiezi tot articolul.
        6) PREAVIZUL (`notice_days`): câte zile înainte trebuie anunțată încetarea sau
           neprelungirea. Numai dacă scrie limpede.
        7) `auto_renew`: true numai dacă scrie că se prelungește de la sine, fără act.
        8) CITATUL (`quote`): fraza din contract, copiată cuvânt cu cuvânt, din care ai
           luat valoarea. Niciodată o frază de-a ta. Fără citat, pui null.
        9) SIGURANȚA (`confidence`): 1 = scrie negru pe alb; 0.8 = scrie, dar textul e
           citit prost de scaner; 0.5 = ai socotit sau ai ales între două variante; sub
           0.5 nu dai valoarea deloc, o lași goală.
        TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        // Furnizorul cere, la răspunsul cu formă fixă, ca fiecare cheie dintr-un
        // obiect să fie și cerută; golul se dă prin „null”, nu prin lipsă.
        $of = fn (string $type) => match ($type) {
            'number' => $schema->number(),
            'integer' => $schema->integer(),
            'boolean' => $schema->boolean(),
            default => $schema->string(),
        };

        $field = fn (string $what, string $type = 'string') => $schema->object([
            'value' => $of($type)->description($what)->nullable()->required(),
            'confidence' => $schema->number()->description('Între 0 și 1.')->required(),
            'quote' => $schema->string()->description('Fraza din contract, copiată întocmai.')->nullable()->required(),
        ])->required();

        return [
            'partner_name' => $field('Numele complet al celeilalte părți, nu al casei noastre.'),
            'partner_tax_id' => $field('Codul fiscal / CUI al partenerului, cu tot cu RO dacă are.'),
            'number' => $field('Numărul contractului sau al actului adițional, așa cum e scris.'),
            'object' => $field('O frază cu obiectul contractului.'),
            'signed_at' => $field('Data semnării, YYYY-MM-DD.'),
            'expires_at' => $field('Data expirării, YYYY-MM-DD.'),
            'value_amount' => $field('Valoarea totală, ca număr curat.', 'number'),
            'value_currency' => $field('Moneda valorii: RON, EUR, USD.'),
            'notice_days' => $field('Zile de preaviz pentru încetare.', 'integer'),
            'payment_terms' => $field('Cum și când se plătește, pe scurt.'),
            'governing_law' => $field('Legea care guvernează contractul.'),
            'auto_renew' => $field('Se prelungește de la sine, fără act?', 'boolean'),
        ];
    }
}
