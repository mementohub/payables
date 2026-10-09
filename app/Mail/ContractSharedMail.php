<?php

namespace App\Mail;

use App\Models\Contract;
use App\Models\ContractShare;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * „Ți-am trimis un contract.”
 *
 * Mailul duce o legătură, nu fișierul: așa se vede cine l-a deschis și când,
 * iar accesul se poate stinge la o dată anume. Contractul rămâne în aplicație,
 * nu umblă prin căsuțe de mail.
 */
class ContractSharedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Contract $contract,
        public ContractShare $share,
        public ?User $sender = null,
        public ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('contracts.mail.from_name', 'Contracts Christian Tour'),
            ),
            subject: sprintf(
                '%s ți-a trimis contractul %s — %s',
                $this->sender?->name ?? 'Christian Tour',
                $this->contract->number,
                $this->contract->partner_name,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.contract-shared',
            with: [
                'contract' => $this->contract,
                'share' => $this->share,
                'sender' => $this->sender,
                'note' => $this->note,
                'url' => route('contracts.shared', $this->share->token),
                'rights' => match ($this->share->permission) {
                    ContractShare::EDIT => 'poți și schimba datele contractului',
                    ContractShare::COMMENT => 'poți citi și comenta',
                    default => 'poți citi și descărca',
                },
            ],
        );
    }
}
