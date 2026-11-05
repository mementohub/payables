<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * O hârtie a casei: o decizie sau o procedură internă.
 *
 * Spre deosebire de contract, n-are cealaltă parte și n-are valoare. Are un
 * emitent, o dată de la care se aplică și oamenii care au apăsat „am luat la
 * cunoștință”.
 */
class CompanyDocument extends Model
{
    public const TYPE_DECISION = 'decizie';

    public const TYPE_PROCEDURE = 'procedura';

    public const TYPES = [self::TYPE_DECISION, self::TYPE_PROCEDURE];

    /** Cum se cheamă fiecare registru, la singular și la plural. */
    public const LABELS = [
        self::TYPE_DECISION => ['one' => 'Decizie', 'many' => 'Decizii', 'article' => 'Decizia'],
        self::TYPE_PROCEDURE => ['one' => 'Procedură', 'many' => 'Proceduri', 'article' => 'Procedura'],
    ];

    /** Cum se cheamă fiecare registru în adresă. */
    public const SLUGS = [
        'decizii' => self::TYPE_DECISION,
        'proceduri' => self::TYPE_PROCEDURE,
    ];

    /** Seria din care se dau numerele. */
    public const SERIES = [
        self::TYPE_DECISION => 'DEC',
        self::TYPE_PROCEDURE => 'PRO',
    ];

    public const STATUS_DRAFT = 'draft';

    /** În vigoare. */
    public const STATUS_ACTIVE = 'active';

    /** Înlocuită de alta mai nouă. */
    public const STATUS_SUPERSEDED = 'superseded';

    /** Abrogată, fără să vină alta în loc. */
    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_SUPERSEDED, self::STATUS_REVOKED];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'effective_at' => 'date',
            'expires_at' => 'date',
            'consent_due_at' => 'date',
            'consent_reminded_at' => 'datetime',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'tags' => 'array',
            'ocr_fields' => 'array',
        ];
    }

    /** Felul documentului, din slugul adresei; null dacă nu e niciunul. */
    public static function typeFromSlug(string $slug): ?string
    {
        return self::SLUGS[mb_strtolower($slug)] ?? null;
    }

    /** Slugul sub care stă registrul în adresă. */
    public static function slug(string $type): string
    {
        return (string) array_search($type, self::SLUGS, true);
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

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CompanyDocumentFile::class)->orderByDesc('version');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CompanyDocumentEvent::class)->latest();
    }

    public function consents(): HasMany
    {
        return $this->hasMany(DocumentConsent::class)->orderByDesc('consented_at');
    }

    /** Fișierul în vigoare: ultima versiune a documentului însuși. */
    public function current(): ?CompanyDocumentFile
    {
        $files = $this->relationLoaded('files') ? $this->files : $this->files()->get();

        return $files->firstWhere('kind', CompanyDocumentFile::KIND_DOCUMENT) ?? $files->first();
    }

    /**
     * Anexele, de la vechi la nou: se citesc odată cu documentul.
     *
     * @return Collection<int, CompanyDocumentFile>
     */
    public function annexes(): Collection
    {
        $files = $this->relationLoaded('files') ? $this->files : $this->files()->get();

        return $files->where('kind', CompanyDocumentFile::KIND_ANNEX)
            ->sortBy(fn (CompanyDocumentFile $file) => $file->created_at?->toIso8601String() ?? '')
            ->values();
    }

    public function isProcedure(): bool
    {
        return $this->type === self::TYPE_PROCEDURE;
    }

    /** A plecat spre companie? Până atunci, nimeni din afara registrului n-o vede. */
    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * Orice hârtie trimisă în companie cere luarea la cunoștință.
     *
     * La început o cereau numai procedurile; deciziile conducerii se dovedesc
     * însă la fel de importante de dovedit citite, așa că amândouă trec prin
     * aceeași pagină și prin același registru. Cât timp stă în ciornă, nu cere
     * nimic: n-a plecat la nimeni.
     */
    public function needsConsent(): bool
    {
        return $this->isPublished();
    }

    public function label(string $which = 'one'): string
    {
        return self::LABELS[$this->type][$which] ?? 'Document';
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }
}
