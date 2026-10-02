<?php

namespace App\Mail;

use App\Models\CashFlowSnapshot;
use App\Services\CashFlow\CashFlowDigest;
use App\Services\Exports\CashFlowExport;
use App\Services\Exports\ReportExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Raportul de trezorerie trimis dimineața.
 *
 * Corpul mailului e cât se citește din mers: banii de azi, săptămânile
 * apropiate, soldul la care se ajunge. Raportul întreg vine atașat, în
 * aceleași cifre ca pe ecran — exportul e făcut din același snapshot.
 */
class CashFlowDailyMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var array<string, mixed> */
    public array $digest;

    public function __construct(public CashFlowSnapshot $snapshot, int $weeks = 8, private string $attach = 'xlsx')
    {
        $this->digest = app(CashFlowDigest::class)->for($snapshot, $weeks);
    }

    public function envelope(): Envelope
    {
        $date = $this->snapshot->built_at?->format('d.m.Y') ?? now()->format('d.m.Y');
        $total = number_format((float) ($this->digest['position_total'] ?? 0), 0, ',', '.');

        return new Envelope(
            subject: 'Trezorerie '.$date.' · poziție '.$total.' '.$this->digest['currency'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.cash-flow-daily',
            with: ['digest' => $this->digest, 'url' => url('/reports/cash-flow')],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if (! in_array($this->attach, ReportExporter::FORMATS, true)) {
            return [];
        }

        $document = app(CashFlowExport::class)->document($this->snapshot);
        $contents = app(ReportExporter::class)->contents($document, $this->attach);
        $name = 'WCFR-'.($this->snapshot->built_at?->format('Y-m-d') ?? now()->format('Y-m-d')).'.'.$this->attach;

        return [
            Attachment::fromData(fn () => $contents, $name)
                ->withMime($this->attach === 'pdf'
                    ? 'application/pdf'
                    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
