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

        $heading = $digest ? 'Contractele săptămânii' : 'Contracte care vin la rând';
        $intro = $digest
            ? 'Tot ce expiră în următoarele '.$horizon.' de zile.'
            : 'Scadențele de azi și preavizele care trebuie date.';

        $sent = [];

        foreach ($this->mailboxes($due) as $email => $theirs) {
            try {
                Mail::to($email)->send(new ContractAlertsMail($this->rows($theirs, $today), $heading, $intro));
            } catch (Throwable $e) {
                $this->error('Mailul către '.$email.' nu a plecat: '.$e->getMessage());

                return self::FAILURE;
            }

            $sent[] = sprintf('%s (%d)', $email, $theirs->count());
        }

        if ($sent === []) {
            $this->error('Nu e nimeni cu rolul Contract Management și nicio adresă dată.');

            return self::FAILURE;
        }

        $this->info(sprintf('%d contracte anunțate: %s.', $due->count(), implode(', ', $sent)));

        return self::SUCCESS;
    }

    /**
     * Cine ce primește.
     *
     * Fiecare om vede în mail exact ce vede în repertoriu — ale lui și ale
     * departamentelor lui. Altfel cel care ține circuitele primea dimineața
     * contractele administrativului, pe care nu le poate nici deschide.
     *
     * @param  Collection<int, Contract>  $due
     * @return array<string, Collection<int, Contract>>
     */
    private function mailboxes(Collection $due): array
    {
        if ($this->option('to')) {
            $boxes = [];

            foreach ($this->addresses((array) $this->option('to')) as $email) {
                $boxes[$email] = $due;
            }

            return $boxes;
        }

        $boxes = [];

        $people = User::query()
            ->whereJsonContains('roles', User::ROLE_CONTRACTS)
            ->orWhereJsonContains('roles', User::ROLE_ADMIN)
            ->with('departments:id')
            ->get()
            ->merge($due->map(fn (Contract $contract) => $contract->owner)->filter())
            ->unique('id');

        foreach ($people as $person) {
            if (! filter_var((string) $person->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $theirs = $due->filter(fn (Contract $contract) => $this->his($person, $contract))->values();

            if ($theirs->isNotEmpty()) {
                $boxes[$person->email] = $theirs;
            }
        }

        return $boxes;
    }

    /**
     * Are omul treabă cu contractul? Aceeași regulă ca în repertoriu: al lui,
     * dat în grija lui, sau al unui departament de-al lui. Cine nu e pus pe
     * niciun departament nu e îngrădit, iar contractul fără departament
     * ajunge la toți cei care țin contracte — altfel n-ar afla nimeni de el.
     */
    private function his(User $user, Contract $contract): bool
    {
        if ($user->seesAllContracts() || $contract->created_by_id === $user->id || $contract->owner_id === $user->id) {
            return true;
        }

        $departments = $user->departments->pluck('id')->all();

        if ($departments === [] || $contract->department_id === null) {
            return true;
        }

        return in_array($contract->department_id, $departments, true);
    }

    /**
     * @param  array<int, string>  $values
     * @return list<string>
     */
    private function addresses(array $values): array
    {
        return collect($values)
            ->flatMap(fn (string $value) => explode(',', $value))
            ->map(fn (string $value) => trim($value))
            ->filter(fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()->values()->all();
    }

    /**
     * @param  Collection<int, Contract>  $contracts
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $contracts, Carbon $today): array
    {
        return $contracts->map(fn (Contract $contract) => [
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
    }
}
