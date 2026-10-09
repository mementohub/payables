<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Ce contracte vin la rând: scadențele apropiate și preavizele care trebuie
 * date. Același croi ca mailul de trezorerie, ca să nu învețe nimeni încă un
 * fel de raport.
 *
 * @param  list<array<string, mixed>>  $rows
 */
class ContractAlertsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $rows,
        public string $heading = 'Contracte care vin la rând',
        public ?string $intro = null,
    ) {}

    public function envelope(): Envelope
    {
        $urgent = collect($this->rows)->filter(fn (array $row) => ($row['days_left'] ?? 99) <= 30)->count();

        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('contracts.mail.from_name', 'Contracts Christian Tour'),
            ),
            subject: sprintf(
                'Contracte · %d de urmărit%s',
                count($this->rows),
                $urgent > 0 ? ', din care '.$urgent.' sub 30 de zile' : '',
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.contract-alerts',
            with: [
                'rows' => $this->rows,
                'heading' => $this->heading,
                'intro' => $this->intro,
                'url' => url('/contracts'),
            ],
        );
    }
}
