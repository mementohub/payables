<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'microsoft_id', 'avatar', 'email_verified_at'])]
#[Hidden(['password', 'microsoft_id', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)->withTimestamps();
    }

    public function isOrdonator(): bool
    {
        return $this->departments()
            ->where('type', Department::TYPE_ORDONATOR)
            ->exists();
    }

    /**
     * @return array<int, int>
     */
    public function departmentIds(?string $type = null): array
    {
        return $this->departments()
            ->when($type, fn ($q, $t) => $q->where('type', $t))
            ->pluck('departments.id')
            ->all();
    }
}
