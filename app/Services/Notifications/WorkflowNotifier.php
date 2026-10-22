<?php

namespace App\Services\Notifications;

use App\Models\Department;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\InvoiceCommented;
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

    /**
     * Ce s-a spus pe o factură — departamentul ei află.
     *
     * Comentariul de la aprobarea de departament pleacă la oamenii
     * departamentului ăluia; cel de la decizia finală, la oamenii tuturor
     * departamentelor facturii, fiindcă pe toți îi privește. Fără comentariu
     * nu pleacă nimic: o aprobare tăcută e tocmai ce nu trebuie anunțat.
     *
     * @param  Collection<int, Department>|null  $departments
     */
    public function commented(
        Invoice $invoice,
        User $actor,
        ?string $comment,
        ?Department $department = null,
        string $stage = InvoiceCommented::STAGE_NOTE,
        ?string $decision = null,
    ): void {
        $comment = trim((string) $comment);

        if ($comment === '' || ! $this->on('commented')) {
            return;
        }

        $departments = $department !== null
            ? collect([$department])
            : $this->departmentsOf($invoice);

        if ($departments->isEmpty()) {
            Log::info('Factura '.$invoice->nr_doc.' n-are departament: comentariul n-are cui pleca.');

            return;
        }

        // Un om pus pe două departamente ale aceleiași facturi primește un
        // singur mail, nu două.
        $people = $departments
            ->flatMap(fn (Department $one) => $this->recipients->departmentPeople($one, $actor))
            ->unique('id')
            ->values();

        $this->send(
            $people,
            fn () => new InvoiceCommented($invoice, $actor, $comment, $department, $stage, $decision),
            'comentariu pe factura '.$invoice->nr_doc,
        );
    }

    /**
     * Departamentele pe care stă factura.
     *
     * @return Collection<int, Department>
     */
    private function departmentsOf(Invoice $invoice): Collection
    {
        return $invoice->departmentApprovals()
            ->with('department')
            ->get()
            ->pluck('department')
            ->filter()
            ->unique('id')
            ->values();
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
