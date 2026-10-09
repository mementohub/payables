<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un contract din repertoriu.
 *
 * Starea nu se ține de mână decât până la semnare: după ea, contractul e activ
 * cât timp n-a trecut data de expirare, iar ziua de după o trece singură în
 * „expirat”. Așa nu depinde de cineva care să-și aducă aminte să schimbe un
 * câmp.
 */
class Contract extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_NEGOTIATION = 'negotiation';

    public const STATUS_APPROVAL = 'approval';

    public const STATUS_SIGNING = 'signing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_TERMINATED = 'terminated';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_NEGOTIATION, self::STATUS_APPROVAL,
        self::STATUS_SIGNING, self::STATUS_ACTIVE, self::STATUS_EXPIRED, self::STATUS_TERMINATED,
    ];

    public const KIND_SUPPLIER = 'supplier';

    public const KIND_CLIENT = 'client';

    public const KIND_GROUP = 'group';

    public const KINDS = [self::KIND_SUPPLIER, self::KIND_CLIENT, self::KIND_GROUP];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'signed_at' => 'date',
            'starts_at' => 'date',
            'expires_at' => 'date',
            'archived_at' => 'datetime',
            'value' => 'decimal:2',
            'auto_renew' => 'boolean',
            'tags' => 'array',
            'ocr_fields' => 'array',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ContractFile::class)->orderByDesc('version');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(ContractShare::class)->latest();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ContractEvent::class)->latest();
    }

    /** Fișierul în vigoare: ultima versiune încărcată. */
    public function current(): ?ContractFile
    {
        return $this->relationLoaded('files')
            ? $this->files->first()
            : $this->files()->first();
    }

    /**
     * Câte zile mai are de trăit; negativ dacă a trecut, null dacă n-are
     * termen (contractele-cadru, pe durată nedeterminată).
     */
    public function daysLeft(?CarbonInterface $today = null): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) ($today ?? now())->startOfDay()->diffInDays($this->expires_at->startOfDay(), false);
    }

    /** Ziua în care trebuie anunțată reînnoirea sau denunțarea. */
    public function noticeOn(): ?CarbonInterface
    {
        if ($this->expires_at === null || $this->notice_days === null) {
            return null;
        }

        return $this->expires_at->copy()->subDays((int) $this->notice_days);
    }

    /**
     * Starea citită, nu cea scrisă: un contract semnat care și-a trecut
     * termenul e expirat, oricât ar scrie în coloană.
     */
    public function state(?CarbonInterface $today = null): string
    {
        if (in_array($this->status, [self::STATUS_TERMINATED, self::STATUS_EXPIRED], true)) {
            return $this->status;
        }

        if ($this->status !== self::STATUS_ACTIVE) {
            return $this->status;
        }

        $left = $this->daysLeft($today);

        return $left !== null && $left < 0 ? self::STATUS_EXPIRED : self::STATUS_ACTIVE;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)->whereNull('archived_at');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
