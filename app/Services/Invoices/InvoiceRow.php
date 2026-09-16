<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * One supplier invoice as the payment check sees it, whether it comes from
 * the invoices synced locally or straight from the OMC database.
 */
final readonly class InvoiceRow
{
    public function __construct(
        public ?int $id,
        public string $tipDoc,
        public string $nrDoc,
        public CarbonInterface $dataDoc,
        public ?CarbonInterface $dataScadenta,
        public ?string $moneda,
        public float $curs,
        public float $valMon,
        public float $valMonPaid,
        public float $valMonStorno,
        public ?string $paymentStatus = null,
        public ?string $description = null,
        public ?CarbonInterface $paidAt = null,
        public ?string $accounts = null,
        public ?string $partner = null,
        public ?string $partnerCui = null,
    ) {}

    public static function fromModel(Invoice $invoice): self
    {
        return new self(
            id: $invoice->id,
            tipDoc: $invoice->tip_doc,
            nrDoc: $invoice->nr_doc,
            dataDoc: $invoice->data_doc->copy()->startOfDay(),
            dataScadenta: $invoice->data_scadenta?->copy()->startOfDay(),
            moneda: $invoice->moneda,
            curs: (float) ($invoice->curs ?? 0) ?: 1.0,
            valMon: (float) $invoice->val_mon,
            valMonPaid: (float) $invoice->val_mon_paid,
            valMonStorno: (float) $invoice->val_mon_storno,
            paymentStatus: $invoice->payment_status,
        );
    }

    /**
     * @param  array<string, mixed>  $row  a `doc` row as OmcReader returns it
     */
    public static function fromOmc(array $row, ?int $localId = null): self
    {
        return new self(
            id: $localId,
            tipDoc: (string) $row['tip_doc'],
            nrDoc: (string) $row['nr_doc'],
            dataDoc: Carbon::parse((string) $row['data_doc'])->startOfDay(),
            dataScadenta: ! empty($row['data_scadenta']) ? Carbon::parse((string) $row['data_scadenta'])->startOfDay() : null,
            moneda: self::text($row['moneda'] ?? null),
            curs: (float) ($row['curs'] ?? 0) ?: 1.0,
            valMon: (float) $row['val_mon'],
            valMonPaid: (float) ($row['val_mon_pl'] ?? 0),
            valMonStorno: (float) ($row['val_mon_dimin_negru'] ?? 0),
            description: self::text($row['description'] ?? null),
            paidAt: ! empty($row['paid_at']) ? Carbon::parse((string) $row['paid_at'])->startOfDay() : null,
            accounts: self::text($row['accounts'] ?? null),
            partner: self::text($row['partener'] ?? null),
            partnerCui: self::text($row['cod_cci'] ?? null),
        );
    }

    /**
     * The ERP document key: the same invoice number repeats across types and years.
     */
    public function key(): string
    {
        return "{$this->dataDoc->toDateString()}|{$this->tipDoc}|{$this->nrDoc}";
    }

    /**
     * Value less the payments allocated to it and the credit notes offset against it.
     */
    public function outstanding(): float
    {
        return round($this->valMon - $this->valMonPaid - $this->valMonStorno, 2);
    }

    public function lei(): float
    {
        return $this->valMon * $this->curs;
    }

    public function status(): string
    {
        if ($this->paymentStatus !== null) {
            return $this->paymentStatus;
        }

        $settled = $this->valMonPaid + $this->valMonStorno;

        if ($settled + 0.01 >= abs($this->valMon)) {
            return Invoice::PAYMENT_PAID;
        }

        return $settled <= 0.009 ? Invoice::PAYMENT_UNPAID : Invoice::PAYMENT_PARTIAL;
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
