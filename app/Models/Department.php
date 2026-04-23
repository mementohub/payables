<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Department extends Model
{
    public const TYPE_SUPERVISOR = 'supervisor';

    public const TYPE_MASTER = 'master';

    public const TYPES = [self::TYPE_SUPERVISOR, self::TYPE_MASTER];

    protected $guarded = [];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(Partner::class, 'partner_department')->withTimestamps();
    }

    public function scopeSupervisors(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_SUPERVISOR);
    }

    public function scopeMasters(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_MASTER);
    }
}
