<?php

namespace App\Services\Notifications;

use App\Models\Department;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\InvoiceDisputed;
use App\Notifications\InvoicesRouted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Vestea care pleacă din fluxul de aprobare.
 *
 * Trimiterea stă deoparte de fluxul propriu-zis și nu se lasă să strice
 * nimic: dacă mailul nu poate pleca, factura tot se contestează și tot se
 * rutează, iar eșecul se scrie în jurnal. Nicio decizie de business nu are
 * voie să cadă fiindcă n-a mers un SMTP.
 */
class WorkflowNotifier
{
    public function __construct(private Recipients $recipients) {}

    /**
     * O factură contestată — Top Management află.
     */
    public function disputed(Invoice $invoice, User $actor, ?Department $department = null, ?string $comment = null): void
    {
        if (! $this->on('disputed')) {
            return;
        }

        $this->send(
            $this->recipients->topManagement($actor),
            fn () => new InvoiceDisputed($invoice, $actor, $department, $comment),
            'factura '.$invoice->nr_doc.' contestată',
        );
    }

    /**
     * Facturi rutate către un departament — șeful lui află.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    public function routed(Department $department, Collection $invoices, User $actor, ?Department $from = null, ?string $reason = null): void
    {
        if (! $this->on('routed') || $invoices->isEmpty()) {
            return;
        }

        $this->send(
            $this->recipients->departmentHeads($department, $actor),
            fn () => InvoicesRouted::for($department, $invoices, $actor, $from, $reason),
            $invoices->count().' facturi rutate la '.$department->name,
        );
    }

    private function on(string $feature): bool
    {
        return (bool) config('notifications.enabled', true) && (bool) config('notifications.'.$feature.'.enabled', true);
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function send(Collection $users, callable $notification, string $what): void
    {
        if ($users->isEmpty()) {
            Log::info('Nu am cui trimite vestea: '.$what);

            return;
        }

        try {
            Notification::send($users, $notification());
        } catch (Throwable $e) {
            Log::warning('Nu am putut trimite vestea ('.$what.'): '.$e->getMessage());
        }
    }
}
