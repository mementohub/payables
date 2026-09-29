<?php

namespace App\Models;

use App\Actions\EInvoices\MatchInvoiceToEInvoice;
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

    protected static function booted(): void
    {
        static::saving(function (Invoice $invoice) {
            if ($invoice->isDirty('nr_doc') || $invoice->nr_doc_key === null) {
                $invoice->nr_doc_key = MatchInvoiceToEInvoice::key($invoice->nr_doc);
            }
        });
    }

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
            'omc_modified_at' => 'datetime',
            'omc_removed_at' => 'datetime',
            'assigned_at' => 'datetime',
            'postponed_until' => 'date',
            'final_decided_at' => 'datetime',
            'approved_amount' => 'float',
        ];
    }

    /**
     * The department that owns most of the invoice's value.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<InvoiceLineDepartment, $this>
     */
    public function lineDepartments(): HasMany
    {
        return $this->hasMany(InvoiceLineDepartment::class);
    }

    /**
     * @return HasMany<InvoiceDepartmentApproval, $this>
     */
    public function departmentApprovals(): HasMany
    {
        return $this->hasMany(InvoiceDepartmentApproval::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function finalDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'final_decided_by_id');
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
     * Cât s-a aprobat la plată din factură — atât intră în rulaj.
     *
     * Aprobarea poate fi și pe o parte: fiecare departament spune cât din
     * partea lui e bună de plată, iar Top Management poate tăia și el suma
     * finală. Nespus înseamnă „tot”, iar totul înseamnă restul de plată, așa
     * cum îl știe ERP-ul. Părțile departamentelor sunt fără TVA, deci se
     * cântăresc proporțional din restul de plată, care e cu TVA.
     */
    public function approvedForPayment(): float
    {
        $outstanding = $this->outstandingAmount();

        if ($this->approved_amount !== null) {
            return round(min($outstanding, (float) $this->approved_amount), 2);
        }

        $approvals = $this->relationLoaded('departmentApprovals')
            ? $this->departmentApprovals
            : $this->departmentApprovals()->get();

        if ($approvals->isEmpty()) {
            return $outstanding;
        }

        $shares = (float) $approvals->sum('amount');
        $approved = 0.0;

        foreach ($approvals as $approval) {
            if ($approval->status !== InvoiceDepartmentApproval::APPROVED) {
                continue;
            }

            $approved += $approval->approved_amount !== null
                ? (float) $approval->approved_amount
                : ($shares > 0 ? $outstanding * ((float) $approval->amount / $shares) : $outstanding);
        }

        return round(min($outstanding, $approved), 2);
    }

    /**
     * Partea departamentului, în bani de plată (cu TVA): din ea se aprobă.
     */
    public function grossShareOf(InvoiceDepartmentApproval $approval): float
    {
        $outstanding = $this->outstandingAmount();
        $shares = (float) ($this->relationLoaded('departmentApprovals')
            ? $this->departmentApprovals->sum('amount')
            : $this->departmentApprovals()->sum('amount'));

        return round($shares > 0 ? $outstanding * ((float) $approval->amount / $shares) : $outstanding, 2);
    }

    /**
     * What the ERP has settled on the document: payments allocated to it plus
     * the offsets against it, counted in the document's own direction so a
     * credit note used up reads as settled like a paid invoice. `val_mon` is
     * the document total, VAT included, so both sides are gross.
     */
    public function settledAmount(): float
    {
        $direction = (float) $this->val_mon < 0 ? -1 : 1;

        return round($direction * ((float) $this->val_mon_paid + (float) $this->val_mon_storno), 2);
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
     * The status shown everywhere: what the ERP settled, and nimic altceva.
     *
     * Plata se face și se stinge în OMC, deci statusul se citește de acolo.
     * A existat și un marcaj manual, în aplicație; s-a scos, fiindcă două
     * adevăruri despre aceeași plată înseamnă, în practică, niciunul.
     */
    public function paymentStatus(): string
    {
        return $this->erpPaymentStatus();
    }

    /**
     * The same rule as {@see self::paymentStatus()} in SQL, so filtering and
     * sorting cannot drift from what the page shows.
     */
    public static function paymentStatusSql(string $prefix = 'invoices.'): string
    {
        $tolerance = self::PAYMENT_TOLERANCE;

        $settled = "(case when {$prefix}val_mon < 0 then -1 else 1 end) * ({$prefix}val_mon_paid + {$prefix}val_mon_storno)";

        return <<<SQL
            case
                when {$settled} + {$tolerance} >= abs({$prefix}val_mon) then 'paid'
                when {$settled} <= {$tolerance} - 0.001 then 'unpaid'
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
