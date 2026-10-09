<?php

namespace App\Mail;

use App\Models\ContractShare;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * „Ți-am trimis câteva contracte.”
 *
 * Când pleacă un teanc, pleacă într-un singur mail: zece mailuri deodată
 * către același om nu-i ajută nimănui. Fiecare contract are legătura lui, cu
 * drepturile și termenul lui, exact ca atunci când e trimis singur.
 *
 * @see ContractSharedMail pentru contractul trimis de unul singur
 */
class ContractsSharedMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  Collection<int, ContractShare>  $shares */
    public function __construct(
        public Collection $shares,
        public ?User $sender = null,
        public ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->shares->count();

        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('contracts.mail.from_name', 'Contracts Christian Tour'),
            ),
            subject: sprintf(
                '%s ți-a trimis %d contracte',
                $this->sender?->name ?? 'Christian Tour',
                $count,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.contracts-shared',
            with: [
                'shares' => $this->shares,
                'sender' => $this->sender,
                'note' => $this->note,
                'rights' => match ($this->shares->first()?->permission) {
                    ContractShare::EDIT => 'poți și schimba datele contractelor',
                    ContractShare::COMMENT => 'poți citi și comenta',
                    default => 'poți citi și descărca',
                },
            ],
        );
    }
}
