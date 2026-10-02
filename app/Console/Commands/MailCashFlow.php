<?php

namespace App\Console\Commands;

use App\Mail\CashFlowDailyMail;
use App\Models\CashFlowSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('cashflow:mail {--to=* : Cui se trimite, în locul listei din config} {--force : Trimite și dacă raportul e vechi sau incomplet}')]
#[Description('Trimite pe e-mail raportul de trezorerie (WCFR 52 Weeks) din ultimul snapshot bun. Programat zilnic dimineața, după construirea de noapte.')]
class MailCashFlow extends Command
{
    public function handle(): int
    {
        if (! config('notifications.enabled', true) || ! config('notifications.cash_flow.enabled', true)) {
            $this->warn('Trimiterea raportului de trezorerie e oprită din config.');

            return self::SUCCESS;
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            $this->error('Nu e trecut niciun destinatar (NOTIFICATIONS_CASH_FLOW_TO).');

            return self::FAILURE;
        }

        // Un raport ciuntit (OMC căzut) nu se trimite în locul celui bun: mai
        // folositor e ultimul raport întreg, cu data lui scrisă pe el.
        $snapshot = CashFlowSnapshot::latestUsable() ?? CashFlowSnapshot::latest();

        if ($snapshot === null || $snapshot->payload === null) {
            $this->error('Nu există niciun raport construit; nu am ce trimite.');

            return self::FAILURE;
        }

        $stale = $snapshot->built_at?->diffInHours(now()) ?? 0;
        $limit = (int) config('notifications.cash_flow.stale_after_hours', 24);

        if ($stale > $limit && ! $this->option('force')) {
            $this->warn(sprintf('Raportul e construit acum %d ore (peste %d); îl trimit oricum, cu data pe el.', $stale, $limit));
        }

        $mail = new CashFlowDailyMail(
            $snapshot,
            (int) config('notifications.cash_flow.weeks', 8),
            (string) config('notifications.cash_flow.attach', 'xlsx'),
        );

        try {
            Mail::to($recipients)->send($mail);
        } catch (Throwable $e) {
            $this->error('Mailul nu a plecat: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Raport din '.$snapshot->built_at?->format('d.m.Y H:i').' trimis către '.implode(', ', $recipients).'.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $raw = $this->option('to') ?: [config('notifications.cash_flow.to', '')];

        return collect($raw)
            ->flatMap(fn ($value) => explode(',', (string) $value))
            ->map(fn (string $value) => trim($value))
            ->filter(fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
