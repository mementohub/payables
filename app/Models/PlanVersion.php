<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O variantă de plan, înghețată: pârghiile așa cum erau și ce ieșea din ele.
 */
class PlanVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'summary' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
