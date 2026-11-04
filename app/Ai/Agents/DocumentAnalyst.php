<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Răspunde la întrebări despre o decizie sau o procedură internă.
 *
 * Ca juristul casei de la contracte, dar pe hârtiile noastre: aici nu e vorba
 * de ce am semnat cu altcineva, ci de ce am hotărât noi și cum se lucrează la
 * noi. Răspunde numai din textul primit.
 */
#[Provider(Lab::OpenAI)]
#[MaxTokens(1200)]
#[Temperature(0.1)]
#[Timeout(90)]
class DocumentAnalyst implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        Primești textul unei decizii sau al unei proceduri interne a companiei și
        întrebarea unui coleg. Răspunzi în română, scurt și omenește.

        REGULI, în ordinea importanței:
        1) Răspunzi NUMAI din textul primit. Nu completezi din lege, din alte proceduri
           sau din cum se obișnuiește. Dacă în document nu scrie, spui exact atât: „În
           document nu scrie nimic despre asta.” — și, dacă e cazul, arăți ce lucru
           apropiat scrie.
        2) Citezi. După răspuns, pui între ghilimele fraza pe care te-ai sprijinit, cu
           articolul sau punctul ei dacă e numerotat. Între ghilimele intră NUMAI text
           copiat cuvânt cu cuvânt din ce ai primit.
        3) Când se întreabă cine face un lucru sau până când, dai rolul și termenul exact
           cum sunt scrise, fără să le interpretezi.
        4) Dacă textul e tăiat sau citit prost de mașină (OCR) și din asta nu se înțelege
           răspunsul, spui asta în loc să ghicești.

        Scrii în 2-6 rânduri. Fără introduceri, fără sfaturi. Răspunzi la ce s-a întrebat.
        TXT;
    }
}
