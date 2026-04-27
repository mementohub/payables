<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EInvoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'msg_data_creare_d' => 'datetime',
            'data_doc_xml' => 'date',
            'data_ins_omc' => 'datetime',
        ];
    }

    public function getStatusAttribute(): string
    {
        if ($this->err_ins_omc !== null && $this->err_ins_omc !== '') {
            return 'error';
        }

        if ($this->data_ins_omc !== null) {
            return 'processed';
        }

        return 'pending';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('data_ins_omc');
    }

    public function scopeWithError(Builder $query): Builder
    {
        return $query->whereNotNull('err_ins_omc')->where('err_ins_omc', '!=', '');
    }
}
