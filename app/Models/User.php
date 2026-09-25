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

#[Fillable(['name', 'email', 'password', 'microsoft_id', 'avatar', 'email_verified_at', 'roles'])]
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
            'roles' => 'array',
        ];
    }

    public const ROLE_ADMIN = 'admin';

    public const ROLE_TOP_MANAGEMENT = 'top_management';

    public const ROLE_FINANCE = 'finance';

    public const ROLE_TREASURY = 'treasury';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_TOP_MANAGEMENT, self::ROLE_FINANCE, self::ROLE_TREASURY];

    /**
     * The departments whose invoices the user approves.
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)->withTimestamps();
    }

    /**
     * Whether the user holds the role; an admin holds them all.
     */
    public function hasRole(string $role): bool
    {
        $roles = (array) ($this->roles ?? []);

        return in_array(self::ROLE_ADMIN, $roles, true) || in_array($role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return in_array(self::ROLE_ADMIN, (array) ($this->roles ?? []), true);
    }

    /**
     * Rolurile care văd facturile tuturor departamentelor: administrarea,
     * finanțele și trezoreria plătesc pentru toată compania, iar Top
     * Management decide pe tot. Restul văd doar ce e al departamentului lor.
     */
    public const ROLES_ACROSS_DEPARTMENTS = [self::ROLE_ADMIN, self::ROLE_FINANCE, self::ROLE_TREASURY, self::ROLE_TOP_MANAGEMENT];

    /**
     * Whether the user sees every department's invoices, or only their own.
     *
     * Un om repartizat pe departamente vede doar departamentele lui; unul fără
     * niciun departament nu e limitat, fiindcă altfel n-ar mai vedea nimic.
     */
    public function seesAllDepartments(): bool
    {
        foreach (self::ROLES_ACROSS_DEPARTMENTS as $role) {
            if (in_array($role, (array) ($this->roles ?? []), true)) {
                return true;
            }
        }

        return $this->departmentIds() === [];
    }

    /**
     * Whether the user speaks for the department on its invoices.
     */
    public function approvesFor(Department|int $department): bool
    {
        $id = $department instanceof Department ? $department->id : $department;

        return $this->isAdmin() || in_array($id, $this->departmentIds(), true);
    }

    /**
     * @return array<int, int>
     */
    public function departmentIds(): array
    {
        return $this->departments()->pluck('departments.id')->all();
    }
}
