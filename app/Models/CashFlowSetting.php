<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashFlowSetting extends Model
{
    public const PARAMETERS = 'parameters';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
