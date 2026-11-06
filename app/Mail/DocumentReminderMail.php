<?php

namespace App\Mail;

use App\Models\CompanyDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * „Mai ai o zi.”
 *
 * Aducerea aminte pleacă pe aceleași adrese ca hârtia: pe adresele de grup stă
 * toată compania, iar dintr-o listă de distribuție nu se poate scoate nimeni.
 * Deci ajunge și la cel care a confirmat deja — de aceea mailul îi spune din
 * prima că n-are nimic de făcut. A doua apăsare oricum nu scrie nimic: o
 * adresă intră o singură dată în registru.
 */
class DocumentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CompanyDocument $document) {}

    /**
     * Câte zile mai sunt până la termen: 1 e mâine, 0 e azi, minus e trecut.
     *
     * Automat pleacă numai cu o zi înainte, dar butonul se apasă când vrea
     * omul, așa că mailul trebuie să spună adevărul în oricare din zile.
     */
    public function days(): ?int
    {
        $due = $this->document->consent_due_at?->copy()->startOfDay();

        return $due === null ? null : (int) round(now()->startOfDay()->diffInDays($due, false));
    }

    /**
     * Cum sună graba: rândul de sus al mailului și începutul subiectului.
     *
     * @return array{badge: string, subject: string, tail: string|null}
     */
    public function urgency(): array
    {
        $days = $this->days();
        $due = $this->document->consent_due_at?->format('d.m.Y');

        return match (true) {
            $days === null => ['badge' => 'Luarea la cunoștință nu s-a făcut încă.', 'subject' => 'Aducere aminte', 'tail' => null],
            $days === 1 => ['badge' => 'Mâine, '.$due.', e ultima zi.', 'subject' => 'Mâine e ultima zi', 'tail' => 'mâine'],
            $days === 0 => ['badge' => 'Azi, '.$due.', e ultima zi.', 'subject' => 'Azi e ultima zi', 'tail' => 'azi'],
            $days < 0 => ['badge' => 'Termenul a trecut pe '.$due.'.', 'subject' => 'Termenul a trecut', 'tail' => 'trecut'],
            default => ['badge' => 'Mai sunt '.$days.' zile: termenul e '.$due.'.', 'subject' => 'Mai sunt '.$days.' zile', 'tail' => null],
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('documents.mail.from_name', 'Administrator Christian Tour'),
            ),
            subject: sprintf(
                '%s — %s %s · %s: de luat la cunoștință',
                $this->urgency()['subject'],
                $this->document->label('one'),
                $this->document->number,
                $this->document->title,
            ),
        );
    }

    public function content(): Content
    {
        $urgency = $this->urgency();

        return new Content(
            view: 'mail.document-reminder',
            with: [
                'document' => $this->document,
                'url' => route('documents.read', $this->document->token),
                'library' => route('documents.library', $this->document->token),
                'badge' => $urgency['badge'],
                'tail' => $urgency['tail'],
                'sentOn' => $this->document->published_at?->timezone('Europe/Bucharest')->format('d.m.Y'),
            ],
        );
    }
}
