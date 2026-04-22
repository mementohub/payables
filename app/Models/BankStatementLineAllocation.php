<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementLineAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data_doc_com' => 'date',
            'val_fin' => 'decimal:4',
            'val_com' => 'decimal:4',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
