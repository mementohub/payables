<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contractul trimis cuiva: o legătură cu drept și cu termen, care se vede
 * dacă a fost deschisă. Fișierul nu pleacă pe e-mail; pleacă legătura.
 */
class ContractShare extends Model
{
    public const VIEW = 'view';

    public const COMMENT = 'comment';

    public const EDIT = 'edit';

    public const PERMISSIONS = [self::VIEW, self::COMMENT, self::EDIT];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'opened_at' => 'datetime',
            'opens' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
