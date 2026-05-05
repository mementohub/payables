<?php

namespace App\Http\Middleware;

use App\Models\Company;
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
        $stickyId = $user ? ((int) session('active_company_id') ?: null) : null;
        $sticky = $stickyId
            ? collect($companies)->firstWhere('id', $stickyId)
            : null;

        if ($stickyId !== null && ! $sticky) {
            session()->forget('active_company_id');
            $stickyId = null;
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? array_merge($user->toArray(), [
                    'is_ordonator' => $user->isOrdonator(),
                ]) : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'companies' => array_map(
                fn ($c) => ['id' => (int) $c->id, 'name' => $c->name],
                $companies,
            ),
            'stickyCompany' => $sticky
                ? ['id' => (int) $sticky->id, 'name' => $sticky->name]
                : null,
            'activeCompany' => $sticky
                ? ['id' => (int) $sticky->id, 'name' => $sticky->name]
                : null,
        ];
    }
}
