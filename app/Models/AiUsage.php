<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O întrebare pusă unui agent și ce a costat ea.
 */
class AiUsage extends Model
{
    protected $table = 'ai_usage';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cost_usd' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
