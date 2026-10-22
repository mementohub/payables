<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\User;
use App\Notifications\InvoiceCommented;
use App\Services\Notifications\WorkflowNotifier;
use Illuminate\Validation\ValidationException;

class InvoiceCommentService
{
    public function __construct(private readonly WorkflowNotifier $notifier) {}

    public function add(Invoice $invoice, User $user, string $body): InvoiceEvent
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Comentariul nu poate fi gol.']);
        }

        $event = InvoiceEvent::create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'type' => InvoiceEvent::TYPE_COMMENTED,
            'body' => $body,
        ]);

        // Și vorba scrisă pe fișă, nu doar cea de la o decizie: altfel
        // comentariul rămâne acolo până dă cineva peste el.
        $this->notifier->commented($invoice, $user, $body, null, InvoiceCommented::STAGE_NOTE);

        return $event;
    }
}
