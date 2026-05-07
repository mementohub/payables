<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class InvoiceCommentService
{
    public function add(Invoice $invoice, User $user, string $body): InvoiceEvent
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Comentariul nu poate fi gol.']);
        }

        return InvoiceEvent::create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'type' => InvoiceEvent::TYPE_COMMENTED,
            'body' => $body,
        ]);
    }
}
