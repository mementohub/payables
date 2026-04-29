<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportUsersRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::query()
            ->withCount('departments')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'initials' => $this->initials($user->name),
                'departments_count' => $user->departments_count,
                'created_at' => $user->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('users/index', [
            'users' => $users,
            'current_user_id' => auth()->id(),
        ]);
    }

    public function importForm(): Response
    {
        return Inertia::render('users/import');
    }

    public function import(ImportUsersRequest $request): RedirectResponse
    {
        $emails = collect(preg_split('/[\s,;]+/', (string) $request->string('emails')))
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values();

        $existing = User::whereIn('email', $emails)->pluck('email')->all();

        $created = 0;

        foreach ($emails->diff($existing) as $email) {
            User::create([
                'name' => Str::before($email, '@'),
                'email' => $email,
                'password' => Str::random(24),
                'email_verified_at' => now(),
            ]);

            $created++;
        }

        $skipped = count($existing);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Importați: {$created}. Existenți (omiși): {$skipped}.",
        ]);

        return to_route('users.index');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('users/edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Utilizator actualizat.']);

        return to_route('users.index');
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_if($user->id === auth()->id(), 422, 'Nu poți șterge propriul cont.');

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Utilizator șters.']);

        return to_route('users.index');
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $letters = array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($parts, 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: '?';
    }
}
