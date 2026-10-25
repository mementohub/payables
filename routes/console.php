<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => Cache::forever('scheduler:heartbeat', now()->toIso8601String()))->everyMinute()->name('scheduler-heartbeat');
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('erp:sync')->everyTenMinutes()->withoutOverlapping(30)->runInBackground();
Schedule::command('erp:sync', ['--days' => (int) config('sync.window_days', 400)])
    ->dailyAt(sprintf('%02d:00', (int) config('sync.nightly_hour', 4)))
    ->timezone((string) config('sync.timezone', 'Europe/Bucharest'))
    ->withoutOverlapping(180)
    ->runInBackground();
Schedule::command('etrip:sync-suppliers')
    ->dailyAt('03:30')
    ->timezone((string) config('sync.timezone', 'Europe/Bucharest'))
    ->withoutOverlapping();

/*
 * Cine stă în spatele facturilor refacturate de Memento Air, International și
 * M&L. Noaptea, după ce s-a adus ce e nou din ERP: interogările bat în bazele
 * celorlalte case, iar acolo ziua se lucrează.
 */
/*
 * Pe ce cont i s-a plătit fiecărui furnizor ultima dată: se citește din
 * extrasele bancare, adică din plățile care chiar s-au dus. Așa, șablonul de
 * bancă vine completat chiar și pentru furnizorii cu patru conturi în ERP și
 * niciunul implicit.
 */
Schedule::command('suppliers:seed-account-choices', ['--months' => 6])
    ->dailyAt('03:40')
    ->timezone((string) config('sync.timezone', 'Europe/Bucharest'))
    ->withoutOverlapping(60)
    ->runInBackground();

/*
 * Conturile furnizorilor pe care OMC nu-i are cu niciun IBAN: furnizorul și-l
 * scrie singur în e-Factură, deci se caută acolo. Noaptea, fiindcă fiecare
 * căutare citește un XML din baza celorlalți.
 */
Schedule::command('suppliers:scan-ibans')
    ->dailyAt('03:10')
    ->timezone((string) config('sync.timezone', 'Europe/Bucharest'))
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('invoices:trace-suppliers', ['--months' => 3])
    ->dailyAt('04:15')
    ->timezone((string) config('sync.timezone', 'Europe/Bucharest'))
    ->withoutOverlapping(120)
    ->runInBackground();

/*
 * Și o trecere largă, duminica: hotelul grecesc facturează uneori la câteva
 * luni după ce sejurul s-a vândut, iar trecerea zilnică nu se mai uită atât
 * de departe înapoi. Fără ea, cheltuiala venită târziu n-ar fi găsită
 * niciodată.
 */
/*
 * Dimineața, Top Management-ul află ce a trecut de departamente și așteaptă
 * semnătura lui. Între aprobarea de departament și cea finală nu trecea
 * nimic, iar facturile stăteau în coadă fără să știe nimeni.
 */
Schedule::command('invoices:mail-awaiting-final')
    ->dailyAt((string) config('notifications.awaiting_final.hour', '09:30'))
    ->timezone((string) config('notifications.cash_flow.timezone', 'Europe/Bucharest'))
    ->when(fn () => (bool) config('notifications.enabled', true) && (bool) config('notifications.awaiting_final.enabled', true))
    ->weekdays()
    ->withoutOverlapping(30);

/*
 * Joia dimineața, trezoreria află ce s-a aprobat și așteaptă plata.
 *
 * Aprobarea se dă într-un loc, plata se face în altul, iar între ele nu
 * trecea nimic: o factură bună de plată putea sta zile întregi fără ca
 * nimeni s-o caute.
 */
Schedule::command('invoices:mail-payable')
    ->weeklyOn(4, '09:00')
    // Ora e cea de la noi din birou, nu a ceasului serverului: fără fusul
    // ăsta, „joi la 9” însemna 9 UTC, adică 12 la București.
    ->timezone((string) config('notifications.cash_flow.timezone', 'Europe/Bucharest'))
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('invoices:trace-suppliers', ['--months' => 14])
    ->weeklyOn(0, '02:00')
    ->timezone((string) config('sync.timezone', 'Europe/Bucharest'))
    ->withoutOverlapping(600)
    ->runInBackground();
// Raportul de trezorerie pleacă pe mail dimineața, după construirea de noapte.
$cashFlowMail = Schedule::command('cashflow:mail')
    ->dailyAt((string) config('notifications.cash_flow.hour', '08:00'))
    ->timezone((string) config('notifications.cash_flow.timezone', 'Europe/Bucharest'))
    ->when(fn () => (bool) config('notifications.enabled', true) && (bool) config('notifications.cash_flow.enabled', true))
    ->withoutOverlapping(30);

// Contractele: dimineața, ce vine la rând; lunea, lista întreagă.
Schedule::command('contracts:alerts')
    ->dailyAt('08:15')
    ->timezone((string) config('notifications.cash_flow.timezone', 'Europe/Bucharest'))
    ->when(fn () => (bool) config('notifications.enabled', true) && (bool) config('contracts.alerts.enabled', true))
    ->weekdays()
    ->withoutOverlapping(30);

Schedule::command('contracts:alerts', ['--digest' => true])
    ->weeklyOn(1, (string) config('contracts.alerts.digest_hour', '08:30'))
    ->timezone((string) config('notifications.cash_flow.timezone', 'Europe/Bucharest'))
    ->when(fn () => (bool) config('notifications.enabled', true) && (bool) config('contracts.alerts.enabled', true))
    ->withoutOverlapping(30);

if (config('notifications.cash_flow.weekdays_only', true)) {
    $cashFlowMail->weekdays();
}
/*
 * Istoricul din care se judecă planul: cum a mers fiecare categorie și
 * fiecare cursă. Se strânge noaptea, fiindcă banii pe cursă se adună în două
 * minute pe an, iar pagina planului nu trebuie să aștepte după ei.
 */
Schedule::command('forecast:history', ['--years' => 3])
    ->weeklyOn(1, '02:30')
    ->timezone((string) config('pnl.timezone', 'Europe/Bucharest'))
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('pnl:build')
    ->dailyAt('05:15')
    ->timezone((string) config('pnl.timezone', 'Europe/Bucharest'))
    ->withoutOverlapping(120)
    ->runInBackground();
Schedule::command('cashflow:build')
    ->dailyAt(sprintf('%02d:%02d', (int) config('cashflow.nightly_hour', 4), (int) config('cashflow.nightly_minute', 30)))
    ->timezone((string) config('cashflow.timezone', 'Europe/Bucharest'))
    ->withoutOverlapping(120)
    ->runInBackground();
