<?php

namespace App\Models;

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

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
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
