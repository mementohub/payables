<?php

namespace App\Models;

use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_furnizor' => 'boolean',
            'is_client' => 'boolean',
            'is_vat_payer' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(PartnerBankAccount::class);
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'partner_department')->withTimestamps();
    }

    public function responsabilDepartments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'partner_department')
            ->where('type', Department::TYPE_RESPONSABIL)
            ->withTimestamps();
    }

    public function scopeFurnizori(Builder $query): Builder
    {
        return $query->where('is_furnizor', true);
    }

    public function scopeClienti(Builder $query): Builder
    {
        return $query->where('is_client', true);
    }
}
