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

    /**
     * Ultimul raport pe care se poate lucra.
     *
     * Când o construcție cade (OMC nu răspunde, de pildă), rezultatul ei e un
     * raport ciuntit: linii goale și o listă de erori. Mai folositor decât
     * nimic e raportul de dinainte, cu data lui scrisă pe el — altfel omul
     * rămâne cu ecranul gol exact când are nevoie de cifre.
     */
    public static function latestUsable(): ?self
    {
        return static::query()
            ->where('status', self::STATUS_OK)
            ->orderByDesc('built_at')
            ->orderByDesc('id')
            ->first();
    }
}
