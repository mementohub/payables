<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function index(): Response
    {
        $departments = Department::query()
            ->with('members:id,name,email')
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (Department $dept) => [
                'id' => $dept->id,
                'name' => $dept->name,
                'type' => $dept->type,
                'members' => $dept->members->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])->values(),
            ]);

        return Inertia::render('departments/index', [
            'departments' => $departments,
            'users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(Department::TYPES)],
        ]);

        Department::create($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Departament creat.']);

        return back();
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(Department::TYPES)],
        ]);

        $department->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Departament actualizat.']);

        return back();
    }

    public function destroy(Department $department): RedirectResponse
    {
        $department->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Departament șters.']);

        return back();
    }

    public function attachMember(Request $request, Department $department): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $department->members()->syncWithoutDetaching([$validated['user_id']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Utilizator adăugat.']);

        return back();
    }

    public function detachMember(Department $department, User $user): RedirectResponse
    {
        $department->members()->detach($user->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Utilizator eliminat.']);

        return back();
    }
}
