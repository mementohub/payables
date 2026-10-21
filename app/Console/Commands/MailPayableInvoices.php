<?php

namespace App\Console\Commands;

use App\Mail\PayableInvoicesMail;
use App\Services\Notifications\PayableDigest;
use App\Services\Notifications\Recipients;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Vestea de joi către trezorerie: ce s-a aprobat și așteaptă să fie plătit.
 *
 * Fără ea, o factură bună de plată putea sta zile întregi fără ca nimeni
 * s-o caute — aprobarea se dă într-un loc, plata se face în altul, iar între
 * ele nu trecea nimic.
 */
#[Signature('invoices:mail-payable
    {--all : Tot ce e aprobat și neplătit, nu doar ce s-a aprobat de săptămâna trecută}
    {--to=* : Cui se trimite, în locul trezoreriei}
    {--force : Trimite și când nu e nimic de plătit}')]
class MailPayableInvoices extends Command
{
    protected $description = 'Trimite trezoreriei facturile aprobate care așteaptă plata';

    public function handle(PayableDigest $digest, Recipients $recipients): int
    {
        if (! config('notifications.enabled', true)) {
            $this->warn('Înștiințările sunt oprite din configurare.');

            return self::SUCCESS;
        }

        // Prima trimitere ia tot ce s-a strâns de la începuturi; pe urmă, doar
        // ce s-a aprobat de la ultima veste încoace, ca lista să nu repete
        // săptămână de săptămână ce trezoreria a văzut deja.
        $all = (bool) $this->option('all') || ! $this->sentBefore();
        $since = $all ? null : Carbon::now()->subWeek()->startOfDay();

        $said = $digest->gather($since);

        if ($said['count'] === 0 && ! $this->option('force')) {
            $this->info('Nicio factură aprobată în așteptare. Nu s-a trimis nimic.');

            return self::SUCCESS;
        }

        $to = (array) $this->option('to');
        $people = $to !== []
            ? collect($to)
            : $recipients->treasury()->pluck('email');

        if ($people->isEmpty()) {
            $this->error('N-are cui să i se trimită: nimeni pe rolul de trezorerie.');

            return self::FAILURE;
        }

        $sent = [];

        foreach ($people as $email) {
            try {
                Mail::to($email)->send(new PayableInvoicesMail($said, $all));
                $sent[] = $email;
            } catch (Throwable $e) {
                // Mailul e o înștiințare, nu o condiție: dacă pică unul, ceilalți
                // îl primesc, iar greșeala rămâne scrisă.
                Log::warning('Lista de plată n-a putut pleca la '.$email.': '.$e->getMessage());
            }
        }

        if ($sent !== [] && $to === []) {
            $this->remember();
        }

        $this->info(sprintf(
            '%d facturi, %s lei cu totul. Trimis la: %s',
            $said['count'],
            number_format((float) collect($said['rows'])->sum('lei'), 2, ',', '.'),
            $sent === [] ? 'nimeni' : implode(', ', $sent),
        ));

        return $sent === [] ? self::FAILURE : self::SUCCESS;
    }

    private function sentBefore(): bool
    {
        return cache()->has('invoices.payable-mail.last');
    }

    private function remember(): void
    {
        cache()->forever('invoices.payable-mail.last', now()->toIso8601String());
    }
}
