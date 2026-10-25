<?php

namespace App\Console\Commands;

use App\Mail\AwaitingFinalMail;
use App\Services\Notifications\AwaitingFinalDigest;
use App\Services\Notifications\Recipients;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('invoices:mail-awaiting-final
    {--to=* : Cui se trimite, în locul Top Management-ului}
    {--force : Trimite și când nu e nimic de semnat}')]
#[Description('Spune Top Management-ului ce facturi au trecut de departamente și așteaptă aprobarea finală.')]
class MailAwaitingFinal extends Command
{
    public function handle(AwaitingFinalDigest $digest, Recipients $recipients): int
    {
        if (! config('notifications.enabled', true) || ! config('notifications.awaiting_final.enabled', true)) {
            $this->warn('Vestea despre aprobările finale e oprită din configurare.');

            return self::SUCCESS;
        }

        $said = $digest->gather();

        if ($said['count'] === 0 && ! $this->option('force')) {
            $this->info('Nicio factură nu așteaptă aprobarea finală. Nu s-a trimis nimic.');

            return self::SUCCESS;
        }

        $to = (array) $this->option('to');
        $people = $to !== []
            ? collect($to)
            : $recipients->topManagement()->pluck('email');

        if ($people->isEmpty()) {
            $this->error('N-are cui să i se trimită: nimeni pe rolul de Top Management.');

            return self::FAILURE;
        }

        $sent = [];

        foreach ($people as $email) {
            try {
                Mail::to($email)->send(new AwaitingFinalMail($said));
                $sent[] = $email;
            } catch (Throwable $e) {
                Log::warning('Vestea despre aprobările finale n-a putut pleca la '.$email.': '.$e->getMessage());
            }
        }

        $this->info(sprintf(
            '%d facturi (%d de plătit, %d deja plătite). Trimis la: %s',
            $said['count'],
            count($said['to_pay']),
            count($said['recorded']),
            $sent === [] ? 'nimeni' : implode(', ', $sent),
        ));

        return $sent === [] ? self::FAILURE : self::SUCCESS;
    }
}
