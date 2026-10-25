<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O lună a anului de bază pe care n-am trăit-o încă, scrisă de mână.
 *
 * Ține aceleași pârghii ca o linie de plan — pasageri, locuri, grad de
 * ocupare, bani pe unitate, sumă de cheltuială — fiindcă asta și e: planul
 * ultimelor luni din anul din care pornește planul anului următor.
 */
class BaseEstimate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'volume' => 'float',
            'capacity' => 'float',
            'load' => 'float',
            'unit_net' => 'float',
            'unit_margin' => 'float',
            'unit_cost' => 'float',
            'amount' => 'float',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** Pasagerii lunii: la charter ies din locuri × grad de ocupare. */
    public function pax(): float
    {
        if ($this->kind === PlanLine::CHARTER) {
            return round((float) $this->capacity * (float) $this->load, 2);
        }

        return (float) $this->volume;
    }

    public function net(): float
    {
        return round($this->pax() * (float) $this->unit_net, 2);
    }

    public function margin(): float
    {
        return round($this->pax() * (float) $this->unit_margin, 2);
    }
}
