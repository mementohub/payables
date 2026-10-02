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
use Illuminate\Support\Collection;

/**
 * Cineva a trimis facturi către un departament: șeful lui află.
 *
 * Rutarea se face în altă parte — la Financiar sau la departamentul care
 * returnează o factură ce nu e a lui — așa că cel care o primește n-are de
 * unde ști, decât dacă intră singur în coadă. Un teanc rutat deodată face un
 * singur mail, nu zece: altfel vestea se îneacă în ea însăși.
 */
class InvoicesRouted extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Câte facturi se scriu pe larg în mail; restul se numără. */
    private const LISTED = 15;

    /**
     * @param  list<array{id: int, nr_doc: string, partner: string, amount: string, due: ?string}>  $invoices
     */
    public function __construct(
        public Department $department,
        public array $invoices,
        public User $actor,
        public ?Department $from = null,
        public ?string $reason = null,
    ) {}

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    public static function for(Department $department, Collection $invoices, User $actor, ?Department $from = null, ?string $reason = null): self
    {
        $rows = $invoices->map(function (Invoice $invoice) {
            $invoice->loadMissing('partner:id,name');

            return [
                'id' => (int) $invoice->id,
                'nr_doc' => (string) $invoice->nr_doc,
                'partner' => $invoice->partner?->name ?? '—',
                'amount' => number_format((float) $invoice->val_mon, 2, ',', '.').' '.$invoice->moneda,
                'due' => $invoice->data_scadenta?->format('d.m.Y'),
            ];
        })->values()->all();

        return new self($department, $rows, $actor, $from, $reason);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->invoices);
        $subject = $count === 1
            ? 'Factură de aprobat la '.$this->department->name.': '.$this->invoices[0]['nr_doc']
            : $count.' facturi de aprobat la '.$this->department->name;

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting($count === 1 ? 'O factură nouă la '.$this->department->name : $count.' facturi noi la '.$this->department->name)
            ->line($this->actor->name.($this->from !== null
                ? ' a mutat de la '.$this->from->name.' către '.$this->department->name.($count === 1 ? ' o factură' : ' '.$count.' facturi').'.'
                : ($count === 1 ? ' a rutat o factură către departamentul dumneavoastră.' : ' a rutat '.$count.' facturi către departamentul dumneavoastră.')));

        if ($this->reason !== null && $this->reason !== '') {
            $mail->line('Motiv: '.$this->reason);
        }

        foreach (array_slice($this->invoices, 0, self::LISTED) as $invoice) {
            $mail->line('• '.$invoice['nr_doc'].' · '.$invoice['partner'].' · '.$invoice['amount'].($invoice['due'] !== null ? ' · scadentă '.$invoice['due'] : ''));
        }

        if ($count > self::LISTED) {
            $mail->line('… și încă '.($count - self::LISTED).'.');
        }

        return $mail
            ->action($count === 1 ? 'Vezi factura' : 'Vezi coada de aprobare', $count === 1
                ? url('/invoices/'.$this->invoices[0]['id'])
                : url('/approvals'))
            ->line('Factura intră la plată după ce o aprobă departamentul.');
    }
}
