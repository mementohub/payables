<?php

namespace App\Mail;

use App\Models\CompanyDocument;
use App\Models\DocumentConsent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Dovada, trimisă chiar celui care a apăsat.
 *
 * Pleacă pe adresa scrisă de el, așa că face două lucruri deodată: îi rămâne
 * în căsuță drept confirmare și, dacă altcineva i-a folosit adresa, află pe loc.
 */
class ConsentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CompanyDocument $document,
        public DocumentConsent $consent,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('documents.mail.from_name', 'Administrator Christian Tour'),
            ),
            subject: sprintf('Confirmare: ai luat la cunoștință procedura %s', $this->document->number),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.consent-receipt',
            with: [
                'document' => $this->document,
                'consent' => $this->consent,
                'url' => route('documents.read', $this->document->token),
                'library' => route('documents.library', $this->document->token),
            ],
        );
    }
}
