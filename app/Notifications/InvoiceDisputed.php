<?php

namespace App\Notifications;

use App\Models\Department;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * O factură a fost contestată: Top Management află imediat.
 *
 * Contestarea oprește o plată, deci e exact genul de lucru care nu are voie
 * să aștepte până intră cineva în aplicație. Mailul spune cine, pentru care
 * departament și de ce, fiindcă motivul e tot ce contează la o contestație.
 */
class InvoiceDisputed extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public User $actor,
        public ?Department $department = null,
        public ?string $comment = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->invoice->loadMissing('partner:id,name');
        $partner = $invoice->partner?->name ?? 'furnizor necunoscut';
        $amount = number_format((float) $invoice->val_mon, 2, ',', '.').' '.$invoice->moneda;
        $where = $this->department !== null ? ' pentru '.$this->department->name : '';

        return (new MailMessage)
            ->subject('Factură contestată: '.$invoice->nr_doc.' — '.$partner)
            ->greeting('Factură contestată')
            ->line($this->actor->name.' a contestat factura '.$invoice->nr_doc.$where.'.')
            ->line('Furnizor: '.$partner)
            ->line('Valoare: '.$amount.($invoice->data_scadenta !== null ? ' · scadentă '.$invoice->data_scadenta->format('d.m.Y') : ''))
            ->line('Motiv: '.($this->comment !== null && $this->comment !== '' ? $this->comment : '— nespus —'))
            ->action('Vezi factura', url('/invoices/'.$invoice->id))
            ->line('Factura stă până când e redeschisă sau decisă final.');
    }
}
