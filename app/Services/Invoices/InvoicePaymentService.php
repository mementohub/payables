<?php

namespace App\Services\Invoices;

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoicePaymentService
{
    public function updateStatus(Invoice $invoice, User $user, string $status, ?string $note = null): void
    {
        if (! in_array($status, Invoice::PAYMENT_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Status plată invalid.']);
        }

        if ($invoice->partener_type !== 'furnizor') {
            throw ValidationException::withMessages(['invoice' => 'Doar facturile primite pot fi marcate.']);
        }

        $isPlatiMember = $user->departments()
            ->where('type', Department::TYPE_PLATI)
            ->exists();

        if (! $isPlatiMember) {
            throw new AuthorizationException('Doar membrii departamentului de plăți pot marca plăți.');
        }

        if ($invoice->payment_status === $status) {
            return;
        }

        DB::transaction(function () use ($invoice, $user, $status, $note) {
            $previous = $invoice->payment_status;
            $now = Carbon::now();

            $invoice->forceFill([
                'payment_status' => $status,
                'payment_status_updated_at' => $now,
                'payment_status_updated_by_id' => $user->id,
            ])->save();

            InvoiceEvent::create([
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'type' => InvoiceEvent::TYPE_PAYMENT_STATUS_CHANGED,
                'body' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'payload' => [
                    'from' => $previous,
                    'to' => $status,
                ],
            ]);
        });
    }
}
