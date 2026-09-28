<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Echipa unui departament.
 *
 * Un om operațional își poate aduce colegii: le dă adresa de e-mail, iar ei
 * intră cu contul Microsoft, deja pregătiți pe departamentele lui. Atât —
 * rolul e tot „operațional”, nu poate da altul și nu poate scoate pe nimeni.
 * Cine trebuie mutat sau ridicat în rol trece pe la un administrator.
 */
class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $departments = $user->departments()->orderBy('name')->get(['departments.id', 'departments.name']);

        return Inertia::render('team/index', [
            'departments' => $departments->map(fn ($department) => ['id' => $department->id, 'name' => $department->name])->all(),
            'members' => User::query()
                ->whereHas('departments', fn ($q) => $q->whereIn('departments.id', $departments->pluck('id')))
                ->with('departments:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'roles'])
                ->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'roles' => array_values((array) ($member->roles ?? [])),
                    'departments' => $member->departments->pluck('name')->all(),
                    'is_me' => $member->is($request->user()),
                ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'emails' => ['required', 'string', 'max:10000'],
        ]);

        $user = $request->user();
        $departments = $user->departments()->pluck('departments.id')->all();

        if ($departments === []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Nu sunteți pe niciun departament, deci nu aveți pe cine autoriza.']);

            return back();
        }

        $emails = collect(preg_split('/[\s,;]+/', (string) $validated['emails']))
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique();

        $added = 0;
        $joined = 0;

        foreach ($emails as $email) {
            $member = User::query()->where('email', $email)->first();

            if ($member === null) {
                $member = User::create([
                    'name' => Str::before($email, '@'),
                    'email' => $email,
                    'password' => Str::random(24),
                    'email_verified_at' => now(),
                    // Rolul nu se alege: cine aduce un coleg aduce un coleg.
                    'roles' => [User::ROLE_OPERATIONAL],
                ]);
                $added++;
            } else {
                $joined++;
            }

            $member->departments()->syncWithoutDetaching($departments);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => sprintf('%d conturi noi, %d colegi adăugați pe departamentele dumneavoastră.', $added, $joined),
        ]);

        return back();
    }
}
