<?php

namespace App\Services\PaymentRequests;

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The register of supplier payment requests: every change is recorded as an
 * event on the request and, when invoices are involved, on their timeline too.
 */
class PaymentRequestService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $invoiceIds
     */
    public function create(User $user, array $attributes, array $invoiceIds = []): PaymentRequest
    {
        return DB::transaction(function () use ($user, $attributes, $invoiceIds) {
            $request = PaymentRequest::create([
                ...$attributes,
                'status' => $attributes['status'] ?? PaymentRequest::STATUS_PAYABLE,
                'created_by_id' => $user->id,
                'status_updated_by_id' => $user->id,
                'status_updated_at' => Carbon::now(),
            ]);

            $this->event($request, $user, PaymentRequestEvent::TYPE_CREATED, $request->note, [
                'status' => $request->status,
                'level' => $request->level,
                'verdict' => $request->verdict,
                'difference_pct' => $request->difference_pct,
            ]);

            foreach (Invoice::query()->whereKey($invoiceIds)->get() as $invoice) {
                $this->linkInvoice($request, $user, $invoice);
            }

            return $request;
        });
    }

    public function changeStatus(PaymentRequest $request, User $user, string $status, ?string $note = null): void
    {
        if (! in_array($status, PaymentRequest::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Status necunoscut.']);
        }

        if ($status === PaymentRequest::STATUS_PAID && ! $this->isPlatiMember($user)) {
            throw new AuthorizationException('Doar membrii departamentului de plăți pot marca cereri ca plătite.');
        }

        $note = trim((string) $note);

        if ($request->status === $status && $note === '') {
            return;
        }

        DB::transaction(function () use ($request, $user, $status, $note) {
            $previous = $request->status;

            $request->forceFill([
                'status' => $status,
                'status_updated_by_id' => $user->id,
                'status_updated_at' => Carbon::now(),
            ])->save();

            $this->event($request, $user, PaymentRequestEvent::TYPE_STATUS_CHANGED, $note !== '' ? $note : null, [
                'from' => $previous,
                'to' => $status,
            ]);
        });
    }

    public function comment(PaymentRequest $request, User $user, string $body): PaymentRequestEvent
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Comentariul nu poate fi gol.']);
        }

        return $this->event($request, $user, PaymentRequestEvent::TYPE_COMMENTED, $body);
    }

    public function linkInvoice(PaymentRequest $request, User $user, Invoice $invoice): void
    {
        if ($invoice->company_id !== $request->company_id || $invoice->partener_type !== 'furnizor') {
            throw ValidationException::withMessages(['invoice_id' => 'Doar facturile primite ale aceleiași companii pot acoperi cererea.']);
        }

        if ($request->invoices()->whereKey($invoice->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($request, $user, $invoice) {
            $request->invoices()->attach($invoice->id);

            $this->event($request, $user, PaymentRequestEvent::TYPE_INVOICE_LINKED, null, $this->invoicePayload($invoice));

            InvoiceEvent::create([
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'type' => InvoiceEvent::TYPE_PAYMENT_REQUEST_LINKED,
                'body' => $request->note,
                'payload' => $this->requestPayload($request),
            ]);
        });
    }

    public function unlinkInvoice(PaymentRequest $request, User $user, Invoice $invoice): void
    {
        DB::transaction(function () use ($request, $user, $invoice) {
            if ($request->invoices()->detach($invoice->id) === 0) {
                return;
            }

            $this->event($request, $user, PaymentRequestEvent::TYPE_INVOICE_UNLINKED, null, $this->invoicePayload($invoice));
        });
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function event(PaymentRequest $request, User $user, string $type, ?string $body = null, ?array $payload = null): PaymentRequestEvent
    {
        return PaymentRequestEvent::create([
            'payment_request_id' => $request->id,
            'user_id' => $user->id,
            'type' => $type,
            'body' => $body,
            'payload' => $payload,
        ]);
    }

    /**
     * @return array{invoice_id: int, tip_doc: string, nr_doc: string, data_doc: ?string}
     */
    private function invoicePayload(Invoice $invoice): array
    {
        return [
            'invoice_id' => $invoice->id,
            'tip_doc' => $invoice->tip_doc,
            'nr_doc' => $invoice->nr_doc,
            'data_doc' => $invoice->data_doc?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(PaymentRequest $request): array
    {
        return [
            'payment_request_id' => $request->id,
            'kind' => $request->kind,
            'status' => $request->status,
            'level' => $request->level,
            'verdict' => $request->verdict,
            'requested_amount' => (float) $request->requested_amount,
            'requested_currency' => $request->requested_currency,
            'difference' => $request->difference !== null ? (float) $request->difference : null,
            'difference_pct' => $request->difference_pct !== null ? (float) $request->difference_pct : null,
        ];
    }

    private function isPlatiMember(User $user): bool
    {
        return $user->departments()->where('type', Department::TYPE_PLATI)->exists();
    }
}
