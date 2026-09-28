<?php

namespace App\Http\Middleware;

use App\Support\ViewAs;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the request through only for a user holding one of the roles
 * (`role:admin`, `role:finance,top_management`); an admin holds them all.
 * Cât ține „Vezi ca”, rolurile cântărite sunt ale omului privit.
 */
class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = ViewAs::effective($request);

        abort_unless($user !== null && collect($roles)->contains(fn (string $role) => $user->hasRole($role)), 403, 'Nu aveți acces la această pagină.');

        return $next($request);
    }
}
