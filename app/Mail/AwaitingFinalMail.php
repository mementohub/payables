<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Ce așteaptă semnătura Top Management-ului.
 */
class AwaitingFinalMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $digest
     */
    public function __construct(public array $digest) {}

    public function envelope(): Envelope
    {
        $count = (int) ($this->digest['count'] ?? 0);
        $stale = count($this->digest['stale'] ?? []);

        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                'Aprobări Christian Tour',
            ),
            subject: sprintf(
                'De aprobat final: %d %s%s',
                $count,
                $count === 1 ? 'factură' : 'facturi',
                $stale > 0 ? sprintf(' · %d de peste %d zile', $stale, (int) ($this->digest['stale_days'] ?? 3)) : '',
            ),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.awaiting-final', with: ['digest' => $this->digest]);
    }
}
