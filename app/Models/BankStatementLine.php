<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data_doc' => 'date',
            'val_mon' => 'decimal:4',
            'val_allocated' => 'decimal:4',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BankStatementLineAllocation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Liniile care au de-a face cu partenerul căutat.
     *
     * Numele apare în mai multe feluri în extras: partenerul recunoscut de
     * OMC, numele scris pe operațiune, cine predă și cine primește banii —
     * pentru un transfer, numele e doar acolo. Le căutăm pe toate, fiindcă
     * omul întreabă „ce i-am plătit lui X”, nu „în ce coloană scrie X”.
     *
     * @param  Builder<BankStatementLine>  $query
     */
    public function scopeForPartner($query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function ($where) use ($like) {
            $where->where('partener_name', 'like', $like)
                ->orWhere('emitent', 'like', $like)
                ->orWhere('cine_preda', 'like', $like)
                ->orWhere('cine_primeste', 'like', $like)
                ->orWhereHas('partner', fn ($partner) => $partner->where('name', 'like', $like)->orWhere('cui', 'like', $like));
        });
    }

    public function getUnallocatedAttribute(): float
    {
        return max(0, (float) $this->val_mon - (float) $this->val_allocated);
    }

    public function getIsUnallocatedAttribute(): bool
    {
        return $this->unallocated > 0.01;
    }
}
