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
 * Ce s-a spus pe o factură ajunge la departamentul ei.
 *
 * Un comentariu scris la aprobare — de departament sau de Top Management —
 * era până acum vizibil doar cui deschidea factura. Dar cel care are de
 * făcut ceva cu el e omul departamentului, iar el n-avea de unde ști că
 * există. Acum pleacă la toți cei repartizați pe departamentul facturii,
 * mai puțin cel care a scris.
 */
class InvoiceCommented extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Comentariu scris la aprobarea de departament. */
    public const STAGE_DEPARTMENT = 'department';

    /** Comentariu scris la decizia finală. */
    public const STAGE_FINAL = 'final';

    /** Comentariu scris pe fișa facturii, în afara unei decizii. */
    public const STAGE_NOTE = 'note';

    public function __construct(
        public Invoice $invoice,
        public User $actor,
        public string $comment,
        public ?Department $department = null,
        public string $stage = self::STAGE_NOTE,
        public ?string $decision = null,
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

        $mail = (new MailMessage)
            ->subject($this->subject().': '.$invoice->nr_doc.' — '.$partner)
            ->greeting($this->subject())
            ->line($this->what())
            ->line('Furnizor: '.$partner)
            ->line('Valoare: '.$amount.($invoice->data_scadenta !== null ? ' · scadentă '.$invoice->data_scadenta->format('d.m.Y') : ''));

        if ($this->department !== null) {
            $mail->line('Departament: '.$this->department->name);
        }

        return $mail
            ->line('Ce a scris: „'.$this->comment.'”')
            ->action('Vezi factura', url('/invoices/'.$invoice->id));
    }

    private function subject(): string
    {
        return match (true) {
            $this->decision === 'disputed' => 'Factură contestată',
            $this->decision === 'postponed' => 'Factură amânată',
            $this->stage === self::STAGE_FINAL => 'Comentariu la aprobarea finală',
            $this->stage === self::STAGE_DEPARTMENT => 'Comentariu la aprobarea de departament',
            default => 'Comentariu pe factură',
        };
    }

    private function what(): string
    {
        $where = $this->department !== null ? ' pentru '.$this->department->name : '';

        return match (true) {
            $this->decision === 'disputed' => $this->actor->name.' a contestat factura '.$this->invoice->nr_doc.$where.'.',
            $this->decision === 'postponed' => $this->actor->name.' a amânat factura '.$this->invoice->nr_doc.$where.'.',
            $this->decision === 'approved' && $this->stage === self::STAGE_FINAL => $this->actor->name.' a aprobat final factura '.$this->invoice->nr_doc.' și a lăsat un comentariu.',
            $this->decision === 'approved' => $this->actor->name.' a aprobat factura '.$this->invoice->nr_doc.$where.' și a lăsat un comentariu.',
            $this->decision === 'reopened' => $this->actor->name.' a redeschis factura '.$this->invoice->nr_doc.'.',
            $this->stage === self::STAGE_FINAL => $this->actor->name.' a scris la decizia finală pe factura '.$this->invoice->nr_doc.'.',
            default => $this->actor->name.' a comentat factura '.$this->invoice->nr_doc.$where.'.',
        };
    }
}
