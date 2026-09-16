<?php

namespace App\Models;

use Database\Factories\CashFlowSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashFlowSnapshot extends Model
{
    /** @use HasFactory<CashFlowSnapshotFactory> */
    use HasFactory;

    public const STATUS_OK = 'ok';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'built_at' => 'datetime',
            'week_start' => 'date',
            'duration_ms' => 'integer',
            'payload' => 'array',
            'sources' => 'array',
        ];
    }

    public static function latest(): ?self
    {
        return static::query()->orderByDesc('built_at')->orderByDesc('id')->first();
    }
}
