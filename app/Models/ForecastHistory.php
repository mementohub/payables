<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cum a mers o categorie sau o cursă într-un an: volum, locuri, bani.
 */
class ForecastHistory extends Model
{
    protected $table = 'forecast_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'volume' => 'float',
            'charter_volume' => 'float',
            'charter_net' => 'float',
            'charter_margin' => 'float',
            'capacity' => 'float',
            'sold' => 'float',
            'cost' => 'float',
            'net' => 'float',
            'margin' => 'float',
        ];
    }
}
