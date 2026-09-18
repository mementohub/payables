<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Approvals\InvoiceWorkflow;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $companies = $user
            ? Company::query()->orderBy('name')->get(['id', 'name'])->all()
            : [];

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? array_merge($user->toArray(), [
                    'roles' => array_values((array) ($user->roles ?? [])),
                    'is_ordonator' => $user->hasRole(User::ROLE_TOP_MANAGEMENT),
                ]) : null,
                // What waits for the user, for the menu badge.
                'pending' => fn () => $user ? $this->pendingFor($user) : 0,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'companies' => array_map(
                fn ($c) => ['id' => (int) $c->id, 'name' => $c->name],
                $companies,
            ),
        ];
    }

    /**
     * Invoices waiting for the user: their departments' share, and the final
     * approval of overhead invoices for Top Management.
     */
    private function pendingFor(User $user): int
    {
        $payable = fn ($query) => $query
            ->where('partener_type', 'furnizor')
            ->whereNull('omc_removed_at')
            ->where('val_mon', '>', 0)
            ->whereRaw('val_mon - val_mon_paid - val_mon_storno > 0.01');

        $departmentIds = $user->isAdmin() ? [] : $user->departmentIds();
        $count = $departmentIds === [] ? 0 : $payable(Invoice::query())
            ->where('approval_status', InvoiceWorkflow::DEPARTMENT)
            ->whereHas('departmentApprovals', fn ($q) => $q->whereIn('department_id', $departmentIds)->where('status', 'pending'))
            ->count();

        if ($user->hasRole(User::ROLE_TOP_MANAGEMENT)) {
            $count += $payable(Invoice::query())
                ->where('approval_status', InvoiceWorkflow::FINAL)
                ->where('approval_track', InvoiceWorkflow::TRACK_INVOICE)
                ->count();
        }

        return $count;
    }
}
