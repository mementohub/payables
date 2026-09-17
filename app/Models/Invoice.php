<?php

namespace App\Models;

use App\Models\Builders\InvoiceBuilder;
use App\Services\Invoices\InvoicePresenter;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public function newEloquentBuilder($query): InvoiceBuilder
    {
        return new InvoiceBuilder($query);
    }

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $guarded = [];

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PARTIAL = 'partial';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_UNPAID,
        self::PAYMENT_PARTIAL,
        self::PAYMENT_PAID,
    ];

    /** Two amounts count as equal below this difference. */
    public const PAYMENT_TOLERANCE = 0.01;

    protected function casts(): array
    {
        return [
            'data_doc' => 'date',
            'data_scadenta' => 'date',
            'data_inchidere' => 'date',
            'data_calatoriei' => 'date',
            'data_doc_baza' => 'date',
            'curs' => 'decimal:6',
            'val_mon' => 'decimal:4',
            'val_mon_tva' => 'decimal:4',
            'val_mon_paid' => 'decimal:4',
            'val_mon_storno' => 'decimal:4',
            'responsabili_approved_at' => 'datetime',
            'is_fully_approved' => 'boolean',
            'fully_approved_at' => 'datetime',
            'payment_status_updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(InvoiceDetail::class)->orderBy('scv');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('data_repartizare');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(InvoiceApproval::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(InvoiceEvent::class);
    }

    public function paymentRequests(): BelongsToMany
    {
        return $this->belongsToMany(PaymentRequest::class, 'payment_request_invoice')->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(InvoiceEvent::class)->where('type', InvoiceEvent::TYPE_COMMENTED);
    }

    public function sourceCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'source_company_id');
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id');
    }

    /**
     * Same-company refacturare link: this invoice's `nr_doc_baza` matches another
     * invoice's `nr_doc` within the same company. Hydrated manually by
     * {@see InvoicePresenter::preloadBazaInvoices()}
     * because Eloquent does not natively support composite-key relations.
     */
    public function bazaInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'nr_doc_baza', 'nr_doc');
    }

    /**
     * Amount still open on the document: value less the payments allocated
     * to it and the credit notes offset against it in the ERP.
     */
    public function outstandingAmount(): float
    {
        return round((float) $this->val_mon - (float) $this->val_mon_paid - (float) $this->val_mon_storno, 2);
    }

    /**
     * What the ERP has settled on the document: payments allocated to it plus
     * the credit notes offset against it. `val_mon` is the document total,
     * VAT included, so both sides of the comparison are gross.
     */
    public function settledAmount(): float
    {
        return round((float) $this->val_mon_paid + (float) $this->val_mon_storno, 2);
    }

    /**
     * The payment status as the ERP knows it.
     */
    public function erpPaymentStatus(): string
    {
        $settled = $this->settledAmount();

        if ($settled + self::PAYMENT_TOLERANCE >= abs((float) $this->val_mon)) {
            return self::PAYMENT_PAID;
        }

        return $settled <= self::PAYMENT_TOLERANCE - 0.001 ? self::PAYMENT_UNPAID : self::PAYMENT_PARTIAL;
    }

    /**
     * The status shown everywhere: what the ERP settled, and while it still
     * shows the document as open, the override the payments department set
     * by hand for a payment the ERP has not recorded yet.
     */
    public function paymentStatus(): string
    {
        $erp = $this->erpPaymentStatus();

        if ($erp === self::PAYMENT_PAID) {
            return $erp;
        }

        return $this->payment_status_manual ?? $erp;
    }

    /**
     * Whether the status shown comes from the manual override rather than
     * from the amounts the ERP settled.
     */
    public function hasPaymentOverride(): bool
    {
        return $this->payment_status_manual !== null
            && $this->erpPaymentStatus() !== self::PAYMENT_PAID
            && $this->payment_status_manual !== $this->erpPaymentStatus();
    }

    /**
     * The same rule as {@see self::paymentStatus()} in SQL, so filtering and
     * sorting cannot drift from what the page shows.
     */
    public static function paymentStatusSql(string $prefix = 'invoices.'): string
    {
        $tolerance = self::PAYMENT_TOLERANCE;

        return <<<SQL
            case
                when {$prefix}val_mon_paid + {$prefix}val_mon_storno + {$tolerance} >= abs({$prefix}val_mon) then 'paid'
                when {$prefix}payment_status_manual is not null then {$prefix}payment_status_manual
                when {$prefix}val_mon_paid + {$prefix}val_mon_storno <= {$tolerance} - 0.001 then 'unpaid'
                else 'partial'
            end
            SQL;
    }

    public function scopeFurnizor(Builder $query): Builder
    {
        return $query->where('partener_type', 'furnizor');
    }

    public function scopeClient(Builder $query): Builder
    {
        return $query->where('partener_type', 'client');
    }
}
