<?php

namespace App\Services\Notifications;

use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Cine primește vestea.
 *
 * Un mail trimis cui nu trebuie e zgomot, iar zgomotul se filtrează după o
 * săptămână și atunci nu mai ajunge nici ce e important. De aceea destinatarii
 * se aleg dintr-un singur loc, pe roluri și pe departament, iar cel care a
 * făcut fapta nu-și primește propria veste.
 */
class Recipients
{
    /**
     * Top Management: cine decide final pe toată compania.
     *
     * Administratorii țin toate rolurile în cod, dar nu și în fapt: un cont de
     * administrare nu e un om de decizie, deci intră doar dacă nu există
     * nimeni cu rolul scris pe el.
     *
     * @return Collection<int, User>
     */
    public function topManagement(?User $except = null): Collection
    {
        $users = $this->withRole(User::ROLE_TOP_MANAGEMENT);

        if ($users->isEmpty()) {
            $users = $this->withRole(User::ROLE_ADMIN);
        }

        return $this->without($users, $except);
    }

    /**
     * Șeful departamentului; nepus, tot departamentul.
     *
     * @return Collection<int, User>
     */
    public function departmentHeads(Department $department, ?User $except = null): Collection
    {
        $head = $department->head_user_id !== null
            ? User::query()->whereKey($department->head_user_id)->get()
            : collect();

        if ($head->isEmpty()) {
            $head = $department->members()->get();
        }

        return $this->without($head, $except);
    }

    /**
     * @return Collection<int, User>
     */
    private function withRole(string $role): Collection
    {
        return User::query()
            ->whereJsonContains('roles', $role)
            ->whereNotNull('email')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function without(Collection $users, ?User $except): Collection
    {
        return $users
            ->filter(fn (User $user) => $except === null || $user->id !== $except->id)
            ->filter(fn (User $user) => filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false)
            ->values();
    }
}
