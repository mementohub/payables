<?php

/*
|--------------------------------------------------------------------------
| Ce trimite aplicația pe e-mail
|--------------------------------------------------------------------------
|
| Trei lucruri pleacă singure: raportul de trezorerie, în fiecare dimineață,
| către cine e trecut aici; vestea că o factură a fost contestată, către Top
| Management; și vestea că cineva a rutat facturi către un departament, către
| șeful acelui departament.
|
| Fiecare se poate stinge separat, iar `enabled` le stinge pe toate — un
| comutator care se poate apăsa fără să repornim nimic e singura frână la
| îndemână dacă pleacă un val de mailuri.
|
*/

return [

    'enabled' => (bool) env('NOTIFICATIONS_ENABLED', true),

    /*
     * În afara producției mailurile merg toate la o singură adresă, ca o
     * probă de pe un calculator de lucru să nu ajungă la oameni adevărați.
     */
    'override_recipient' => env('NOTIFICATIONS_OVERRIDE_RECIPIENT'),

    'cash_flow' => [
        'enabled' => (bool) env('NOTIFICATIONS_CASH_FLOW_ENABLED', true),
        // Raportul se citește în zilele de lucru; sâmbăta și duminica nu pleacă.
        'weekdays_only' => (bool) env('NOTIFICATIONS_CASH_FLOW_WEEKDAYS_ONLY', true),
        // Adrese în plus față de Top Management, separate prin virgulă.
        'to' => env('NOTIFICATIONS_CASH_FLOW_TO', ''),
        'hour' => env('NOTIFICATIONS_CASH_FLOW_HOUR', '08:00'),
        'timezone' => env('NOTIFICATIONS_CASH_FLOW_TIMEZONE', 'Europe/Bucharest'),
        // Câte săptămâni din prognoză intră în corpul mailului; restul sunt în fișier.
        'weeks' => (int) env('NOTIFICATIONS_CASH_FLOW_WEEKS', 8),
        // xlsx, pdf sau none.
        'attach' => env('NOTIFICATIONS_CASH_FLOW_ATTACH', 'xlsx'),
        // Peste atâtea ore de la construire, raportul e vechi și mailul o spune.
        'stale_after_hours' => (int) env('NOTIFICATIONS_CASH_FLOW_STALE_HOURS', 24),
    ],

    'disputed' => [
        'enabled' => (bool) env('NOTIFICATIONS_DISPUTED_ENABLED', true),
    ],

    'routed' => [
        'enabled' => (bool) env('NOTIFICATIONS_ROUTED_ENABLED', true),
    ],

];
