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

    /** Linia se mută pe următoarea: modificare sau reevaluare, fără P&L. */
    public const REMEASURE = 'remeasure';

    /** Încetare: ies și dreptul de utilizare, și datoria, cu câștig sau pierdere. */
    public const DERECOGNISE = 'derecognise';

    /** Linie anulată din calcul (dublură sau spațiu care n-a existat). */
    public const CANCELLED = 'cancelled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'event_on' => 'date',
            'opening_on' => 'date',
            'opening_rou' => 'float',
            'opening_liability' => 'float',
            'monthly_depreciation' => 'float',
            'present_value' => 'float',
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
        if ($this->term_months !== null && (int) $this->term_months > 0) {
            return (int) $this->term_months;
        }

        $start = Carbon::parse($this->starts_on)->startOfMonth();
        $end = Carbon::parse($this->ends_on)->startOfMonth();

        return max(1, (int) $start->diffInMonths($end) + 1);
    }

    /**
     * Linia care poartă dreptul de utilizare al grupului.
     *
     * Un contract are adesea mai multe linii — treapta de preț, luna
     * gratuită, actul adițional — dar activul e unul singur: stă pe linia
     * de bază, iar celelalte doar îi adaugă sau îi scad din valoare.
     */
    public function leads(): bool
    {
        return $this->group_no === null || (int) $this->group_no === (int) $this->line_no;
    }

    /** Luna în care se citește rata: a începerii sau a modificării. */
    public function rateMonth(): string
    {
        return Carbon::parse($this->starts_on)->format('Y-m');
    }

    public function label(): string
    {
        return trim($this->lessor.($this->location !== null && $this->location !== '' ? ' · '.$this->location : ''));
    }
}
