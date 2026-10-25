<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O pârghie din plan, pe o lună: pasageri, locuri, grad de ocupare, venit și
 * marjă de pasager, sau o sumă de cheltuială.
 */
class PlanLine extends Model
{
    /** Categorie de produs: se plănuiește pe pasageri. */
    public const CATEGORY = 'category';

    /** Destinație de charter: se plănuiește pe locuri și grad de ocupare. */
    public const CHARTER = 'charter';

    /** Linie de cheltuială din P&L. */
    public const OPEX = 'opex';

    /** Amortizare, rezultat financiar, impozit. */
    public const BELOW = 'below';

    /** Turismul se numără pe pasageri. */
    public const PAX = 'pax';

    /** Corporate-ul din Tina se numără pe servicii facturate. */
    public const SERVICE = 'serviciu';

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
            'manual' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Pasagerii lunii: la charter ies din locuri × grad de ocupare. */
    public function pax(): float
    {
        if ($this->kind === self::CHARTER) {
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

    /** Locurile rămase nevândute pe cursa asta. */
    public function emptySeats(): float
    {
        if ($this->kind !== self::CHARTER) {
            return 0.0;
        }

        return round(max(0.0, (float) $this->capacity - $this->pax()), 2);
    }

    /**
     * Cât ne costă locurile goale: avionul se plătește întreg.
     */
    public function emptyCost(): float
    {
        return round($this->emptySeats() * (float) $this->unit_cost, 2);
    }

    /**
     * De la ce grad de ocupare cursa își acoperă zborul: costul avionului
     * împărțit la cât lasă un pasager.
     */
    public function breakEven(): ?float
    {
        if ($this->kind !== self::CHARTER || (float) $this->unit_net <= 0 || (float) $this->unit_cost <= 0) {
            return null;
        }

        return round((float) $this->unit_cost / (float) $this->unit_net, 4);
    }
}
