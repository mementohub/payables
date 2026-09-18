<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    public const GROUP_PRODUCT = 'product';

    public const GROUP_CHANNEL = 'channel';

    public const GROUP_SUPPORT = 'support';

    public const GROUPS = [self::GROUP_PRODUCT, self::GROUP_CHANNEL, self::GROUP_SUPPORT];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    /**
     * @return HasMany<Department, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id');
    }

    /**
     * Department id by code, for the routing rules.
     *
     * @return array<string, int>
     */
    public static function idsByCode(): array
    {
        return static::query()->whereNotNull('code')->pluck('id', 'code')->all();
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return HasMany<InvoiceDepartmentApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(InvoiceDepartmentApproval::class);
    }
}
