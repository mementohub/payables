<?php

namespace App\Console\Commands;

use App\Mail\ContractAlertsMail;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('contracts:alerts {--digest : Trimite lista întreagă, nu doar scadențele zilei} {--to=* : Cui se trimite, în locul responsabililor}')]
#[Description('Dă de veste ce contracte expiră și unde trebuie dat preaviz. Programat în fiecare dimineață; lunea trimite și lista întreagă.')]
class MailContractAlerts extends Command
{
    public function handle(): int
    {
        if (! config('notifications.enabled', true) || ! config('contracts.alerts.enabled', true)) {
            $this->warn('Veștile despre contracte sunt oprite din config.');

            return self::SUCCESS;
        }

        $today = Carbon::today();
        $digest = (bool) $this->option('digest');
        $horizon = (int) config('contracts.alerts.horizon_days', 90);
        $marks = (array) config('contracts.alerts.days', [90, 60, 30, 15, 7, 1]);

        $contracts = Contract::query()
            ->active()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', $today->copy()->subDays(7)->toDateString())
            ->whereDate('expires_at', '<=', $today->copy()->addDays($horizon)->toDateString())
            ->with(['department:id,name', 'owner:id,name,email'])
            ->orderBy('expires_at')
            ->get();

        // Zilnic se dau de veste numai pragurile („mai sunt 30 de zile”) și
        // preavizul care expiră azi: altfel ar pleca același mail în fiecare
        // dimineață, iar oamenii l-ar filtra în a treia zi.
        $due = $digest
            ? $contracts
            : $contracts->filter(function (Contract $contract) use ($today, $marks) {
                $left = $contract->daysLeft($today);
                $notice = $contract->noticeOn();

                return in_array($left, $marks, true)
                    || ($notice !== null && $notice->isSameDay($today));
            });

        if ($due->isEmpty()) {
            $this->info('Niciun contract de anunțat azi.');

            return self::SUCCESS;
        }

        $rows = $due->map(fn (Contract $contract) => [
            'number' => $contract->number,
            'title' => $contract->title,
            'partner' => $contract->partner_name,
            'department' => $contract->department?->name,
            'owner' => $contract->owner?->name,
            'value' => $contract->value !== null ? (float) $contract->value : null,
            'currency' => $contract->currency,
            'expires_at' => $contract->expires_at?->format('d.m.Y'),
            'days_left' => $contract->daysLeft($today),
            'notice' => $contract->noticeOn()?->format('d.m.Y'),
        ])->values()->all();

        $recipients = $this->recipients($due);

        if ($recipients === []) {
            $this->error('Nu e nimeni cu rolul Contract Management și nicio adresă dată.');

            return self::FAILURE;
        }

        try {
            Mail::to($recipients)->send(new ContractAlertsMail(
                $rows,
                $digest ? 'Contractele săptămânii' : 'Contracte care vin la rând',
                $digest
                    ? 'Tot ce expiră în următoarele '.$horizon.' de zile.'
                    : 'Scadențele de azi și preavizele care trebuie date.',
            ));
        } catch (Throwable $e) {
            $this->error('Mailul nu a plecat: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%d contracte anunțate către %s.', count($rows), implode(', ', $recipients)));

        return self::SUCCESS;
    }

    /**
     * Cui se trimite: rolul Contract Management, plus responsabilii
     * contractelor din listă — ei sunt cei care au ceva de făcut.
     *
     * @param  Collection<int, Contract>  $contracts
     * @return list<string>
     */
    private function recipients(Collection $contracts): array
    {
        if ($this->option('to')) {
            return collect($this->option('to'))
                ->flatMap(fn (string $value) => explode(',', $value))
                ->map(fn (string $value) => trim($value))
                ->filter(fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) !== false)
                ->unique()->values()->all();
        }

        return User::query()
            ->whereJsonContains('roles', User::ROLE_CONTRACTS)
            ->pluck('email')
            ->merge($contracts->map(fn (Contract $contract) => $contract->owner?->email))
            ->filter(fn (?string $email) => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
