<?php

namespace App\Mail;

use App\Services\Xlsx\XlsxWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * „Aveți facturi aprobate, gata de plată.”
 *
 * Pleacă joia la prânz către trezorerie: până atunci, cine aproba o factură
 * nu avea cum să știe dacă a fost și văzută de cine face plata, iar o
 * factură bună de plată putea sta zile întregi fără ca nimeni s-o caute.
 */
class PayableInvoicesMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  array<string, mixed>  $digest */
    public function __construct(public array $digest, public bool $firstRun = false) {}

    public function envelope(): Envelope
    {
        $count = (int) ($this->digest['count'] ?? 0);
        $overdue = count($this->digest['overdue'] ?? []);

        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('mail.from.name', 'Receivables & Payables'),
            ),
            subject: match (true) {
                $count === 0 => 'Nicio factură de plătit săptămâna asta',
                $this->firstRun => sprintf('%d facturi aprobate, în așteptarea plății', $count),
                $overdue > 0 => sprintf('%d facturi de plătit, din care %d restante', $count, $overdue),
                default => sprintf('%d facturi aprobate, gata de plată', $count),
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.payable-invoices',
            with: ['digest' => $this->digest, 'firstRun' => $this->firstRun],
        );
    }

    /**
     * Lista, și ca fișier.
     *
     * Trezoreria lucrează cu ea în foaie: bifează, adună, o pune lângă
     * extrasul de bancă. Un tabel în mail se citește, dar nu se lucrează în el.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $rows = (array) ($this->digest['rows'] ?? []);

        if ($rows === []) {
            return [];
        }

        $file = tempnam(sys_get_temp_dir(), 'plati-').'.xlsx';

        XlsxWriter::write(
            $file,
            ['Furnizor', 'Departament', 'Tip', 'Număr', 'Data', 'Scadență', 'Monedă', 'De plătit', 'În lei', 'Restanță', 'Aprobat de', 'Aprobat la'],
            $this->sheet($rows),
            'De plătit',
        );

        return [
            Attachment::fromPath($file)
                ->as('facturi-de-platit-'.now()->format('Ymd').'.xlsx')
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return \Generator<int, list<mixed>>
     */
    private function sheet(array $rows): \Generator
    {
        foreach ($rows as $row) {
            yield [
                (string) $row['partner'],
                (string) ($row['department'] ?? ''),
                (string) ($row['tip_doc'] ?? ''),
                (string) $row['nr_doc'],
                $row['data_doc']?->toDateString() ?? '',
                $row['data_scadenta']?->toDateString() ?? '',
                (string) $row['moneda'],
                (float) $row['rest'],
                (float) $row['lei'],
                $row['overdue'] ? 'da' : '',
                (string) ($row['approved_by'] ?? ''),
                $row['approved_at']?->toDateString() ?? '',
            ];
        }
    }
}
