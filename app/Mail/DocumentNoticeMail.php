<?php

namespace App\Mail;

use App\Models\CompanyDocument;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * „A apărut o decizie / o procedură nouă.”
 *
 * Mailul duce o legătură, nu fișierul: documentul rămâne în aplicație, iar la
 * proceduri legătura e chiar locul unde omul apasă „am citit și am luat la
 * cunoștință”.
 */
class DocumentNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CompanyDocument $document,
        public ?User $sender = null,
        public ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('documents.mail.from_name', 'Administrator Christian Tour'),
            ),
            // „Procedură nouă — PRO-2026-0001 · Concediul de odihnă: de citit
            // și de luat la cunoștință” — subiectul spune din căsuță ce fel de
            // hârtie e și ce are omul de făcut cu ea.
            subject: $this->document->needsConsent()
                ? sprintf('%s nouă — %s · %s: de citit și de luat la cunoștință', $this->document->label('one'), $this->document->number, $this->document->title)
                : sprintf('%s %s — %s', $this->document->label('one'), $this->document->number, $this->document->title),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.document-notice',
            with: [
                'document' => $this->document,
                'sender' => $this->sender,
                'note' => $this->note,
                'url' => route('documents.read', $this->document->token),
                // Raftul cu toate hârtiile casei: de aici intră în el cine n-are cont.
                'library' => route('documents.library', $this->document->token),
                'consent' => $this->document->needsConsent(),
            ],
        );
    }
}
