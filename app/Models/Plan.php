<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un plan de an: scenariul, anul plănuit și anul din care s-a pornit.
 */
class Plan extends Model
{
    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // „kind|key” pentru fiecare rând scos din plan cu mâna.
            'removed' => 'array',
            'seeded_at' => 'datetime',
        ];
    }

    /** Rândul ăsta a fost scos din plan? */
    public function isRemoved(string $kind, string $key): bool
    {
        return in_array($kind.'|'.$key, (array) ($this->removed ?? []), true);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PlanLine::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class)->latest();
    }
}
