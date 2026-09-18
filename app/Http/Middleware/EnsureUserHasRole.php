<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the request through only for a user holding one of the roles
 * (`role:admin`, `role:finance,top_management`); an admin holds them all.
 */
class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user !== null && collect($roles)->contains(fn (string $role) => $user->hasRole($role)), 403, 'Nu aveți acces la această pagină.');

        return $next($request);
    }
}
