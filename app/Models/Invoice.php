<?php

namespace App\Models;

use App\Models\Builders\InvoiceBuilder;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected function casts(): array
    {
        return [
            'data_doc' => 'date',
            'data_scadenta' => 'date',
            'data_inchidere' => 'date',
            'curs' => 'decimal:6',
            'val_mon' => 'decimal:4',
            'val_mon_tva' => 'decimal:4',
            'val_mon_paid' => 'decimal:4',
            'supervisors_approved_at' => 'datetime',
            'is_fully_approved' => 'boolean',
            'fully_approved_at' => 'datetime',
        ];
    }

    public function getPaymentStatusAttribute(): string
    {
        $total = (float) $this->val_mon;
        $paid = (float) $this->val_mon_paid;

        if ($paid <= 0.009) {
            return 'unpaid';
        }

        if ($paid + 0.01 >= $total) {
            return 'paid';
        }

        return 'partial';
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

    public function scopeFurnizor(Builder $query): Builder
    {
        return $query->where('partener_type', 'furnizor');
    }

    public function scopeClient(Builder $query): Builder
    {
        return $query->where('partener_type', 'client');
    }
}
