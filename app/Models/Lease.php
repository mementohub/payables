<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un contract de închiriere, cu ce-i trebuie standardului IFRS 16.
 */
class Lease extends Model
{
    /** Scris de mână sau propus din registru, încă nefolosit în raport. */
    public const DRAFT = 'draft';

    /** Confirmat: raportul îl socotește. */
    public const ACTIVE = 'active';

    /** Încheiat înainte de termen. */
    public const ENDED = 'ended';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'monthly_payment' => 'decimal:2',
            'discount_rate' => 'decimal:4',
            'indexation' => 'decimal:2',
            'in_advance' => 'boolean',
            'exempt' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** Contractul scanat din repertoriu, dacă a fost legat. */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE)->where('exempt', false);
    }

    /** Câte luni ține contractul, de la început până la sfârșit inclusiv. */
    public function months(): int
    {
        $start = Carbon::parse($this->starts_on)->startOfMonth();
        $end = Carbon::parse($this->ends_on)->startOfMonth();

        return max(1, (int) $start->diffInMonths($end) + 1);
    }

    public function label(): string
    {
        return trim($this->lessor.($this->location !== null && $this->location !== '' ? ' · '.$this->location : ''));
    }
}
