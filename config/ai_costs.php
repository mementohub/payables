<?php

/*
|--------------------------------------------------------------------------
| Cât costă o întrebare pusă unui agent
|--------------------------------------------------------------------------
|
| Furnizorul nu spune în răspuns cât a costat: spune doar câți „token-i” a
| citit și câți a scris. Prețul îl știm de pe pagina lui de tarife și îl
| ținem aici, în dolari pe milionul de token-i.
|
| DE VERIFICAT din când în când la platform.openai.com/pricing: tarifele se
| schimbă, iar un preț vechi arată o socoteală frumoasă și greșită. Un model
| care lipsește de aici nu oprește nimic — se arată numărul de token-i și se
| spune pe față că prețul nu e știut.
|
*/

return [

    /** Dolari pe milionul de token-i: ce citește modelul și ce scrie. */
    'prices' => [
        'gpt-5.4' => ['in' => 1.25, 'out' => 10.00, 'cached_in' => 0.125],
        'gpt-5.4-nano' => ['in' => 0.05, 'out' => 0.40, 'cached_in' => 0.005],
        'gpt-5.4-pro' => ['in' => 15.00, 'out' => 120.00, 'cached_in' => 1.50],
        'gpt-4o-mini' => ['in' => 0.15, 'out' => 0.60, 'cached_in' => 0.075],
        'gpt-4.1-mini' => ['in' => 0.40, 'out' => 1.60, 'cached_in' => 0.10],
    ],

    /*
     * Cât face un dolar în lei. Nu umblăm după cursul zilei pentru niște
     * bănuți: e o socoteală de orientare, nu una de contabilitate.
     */
    'usd_ron' => (float) env('AI_USD_RON', 4.60),

];
