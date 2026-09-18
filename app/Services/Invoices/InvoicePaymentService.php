<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoicePaymentService
{
    /** Passed as the status to drop the override and follow the ERP again. */
    public const AUTO = 'auto';

    /**
     * Set or clear the manual override. The status shown on the invoice comes
     * from the amounts the ERP settled; the override only speaks for a
     * payment the ERP has not recorded yet, and stops counting as soon as the
     * ERP settles the document.
     */
    public function updateStatus(Invoice $invoice, User $user, string $status, ?string $note = null): void
    {
        if ($status !== self::AUTO && ! in_array($status, Invoice::PAYMENT_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Status plată invalid.']);
        }

        $override = $status === self::AUTO ? null : $status;

        if ($invoice->partener_type !== 'furnizor') {
            throw ValidationException::withMessages(['invoice' => 'Doar facturile primite pot fi marcate.']);
        }

        if (! $user->hasRole(User::ROLE_TREASURY)) {
            throw new AuthorizationException('Doar Trezoreria poate marca plăți.');
        }

        if ($invoice->payment_status_manual === $override) {
            return;
        }

        DB::transaction(function () use ($invoice, $user, $override, $note) {
            $previous = $invoice->paymentStatus();
            $now = Carbon::now();

            $invoice->forceFill([
                'payment_status_manual' => $override,
                'payment_status_updated_at' => $override === null ? null : $now,
                'payment_status_updated_by_id' => $override === null ? null : $user->id,
            ])->save();

            InvoiceEvent::create([
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'type' => InvoiceEvent::TYPE_PAYMENT_STATUS_CHANGED,
                'body' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'payload' => [
                    'from' => $previous,
                    'to' => $invoice->paymentStatus(),
                    'override' => $override,
                ],
            ]);
        });
    }
}
