<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O bucată de buget și starea ei: cine o scrie, dacă a trimis-o și dacă a
 * fost primită.
 */
class PlanSection extends Model
{
    /** Se scrie în ea. */
    public const DRAFT = 'draft';

    /** Trimisă spre consolidare: nu se mai scrie până nu se dă înapoi. */
    public const SUBMITTED = 'submitted';

    /** Primită de conducere. Tot încuiată. */
    public const ACCEPTED = 'accepted';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_id');
    }

    /** Încuiată înseamnă că numai conducerea mai poate umbla la ea. */
    public function locked(): bool
    {
        return $this->status !== self::DRAFT;
    }
}
