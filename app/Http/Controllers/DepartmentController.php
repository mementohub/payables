<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    /**
     * The departments invoices are routed to, by group, with the people who
     * approve for each.
     */
    public function index(): Response
    {
        return Inertia::render('departments/index', [
            'departments' => Department::query()
                ->whereNotNull('code')
                ->with('members:id,name,email')
                ->withCount(['approvals as pending_count' => fn ($q) => $q->where('status', 'pending')])
                ->orderBy('sort')
                ->orderBy('name')
                ->get()
                ->map(fn (Department $department) => [
                    'id' => $department->id,
                    'code' => $department->code,
                    'name' => $department->name,
                    'group' => $department->group,
                    'parent_id' => $department->parent_id,
                    'is_active' => $department->is_active,
                    'pending_count' => (int) $department->pending_count,
                    'members' => $department->members->map(fn (User $user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ])->values(),
                ]),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $code = Str::slug($validated['name'], '_');

        Department::query()->create([
            ...$validated,
            'code' => Department::query()->where('code', $code)->exists() ? $code.'_'.Str::lower(Str::random(4)) : $code,
            'sort' => (int) Department::query()->max('sort') + 1,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Departament creat.']);

        return back();
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $department->update([...$this->validated($request, $department), 'is_active' => $request->boolean('is_active', true)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Departament actualizat.']);

        return back();
    }

    /**
     * @return array{name: string, group: string, parent_id: ?int}
     */
    private function validated(Request $request, ?Department $department = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'group' => ['required', Rule::in(Department::GROUPS)],
            'parent_id' => ['nullable', 'integer', Rule::exists('departments', 'id'), Rule::notIn(array_filter([$department?->id]))],
        ]);
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
