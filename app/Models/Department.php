<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Department extends Model
{
    public const TYPE_RESPONSABIL = 'responsabil';

    public const TYPE_ORDONATOR = 'ordonator';

    public const TYPES = [self::TYPE_RESPONSABIL, self::TYPE_ORDONATOR];

    protected $guarded = [];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(Partner::class, 'partner_department')->withTimestamps();
    }

    public function scopeResponsabili(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_RESPONSABIL);
    }

    public function scopeOrdonatori(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_ORDONATOR);
    }
}
