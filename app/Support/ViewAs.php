<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * „Vezi ca”: un administrator se uită prin ochii altui om. Cât ține
 * previzualizarea, meniul, zonele și listele se desenează după drepturile
 * aceluia — contul, sesiunea și deciziile rămân însă ale administratorului.
 *
 * Previzualizarea nu dă drepturi, doar le strânge: un administrator le are
 * oricum pe toate, iar ieșirea din ea („/view-as”) e mereu deschisă.
 */
final class ViewAs
{
    /** Cheia din sesiune care ține omul privit. */
    public const SESSION = 'view_as_user_id';

    /** Cheia sub care se ține omul găsit, cât durează cererea. */
    private const MEMO = 'view_as_user';

    /**
     * Pornește previzualizarea pentru omul dat; `null`, `0` sau el însuși o
     * opresc. Doar un administrator poate privi prin ochii cuiva.
     */
    public static function set(Request $request, ?int $userId): void
    {
        $actor = $request->user();
        $request->attributes->remove(self::MEMO);

        if ($actor === null || ! $actor->isAdmin() || ! $userId || $userId === $actor->id) {
            $request->session()->forget(self::SESSION);

            return;
        }

        $request->session()->put(self::SESSION, $userId);
    }

    /** Omul privit, dacă previzualizarea e pornită și încă e valabilă. */
    public static function user(Request $request): ?User
    {
        if ($request->attributes->has(self::MEMO)) {
            return $request->attributes->get(self::MEMO);
        }

        $actor = $request->user();
        $id = $request->hasSession() ? (int) $request->session()->get(self::SESSION) : 0;
        $user = $actor !== null && $actor->isAdmin() && $id !== 0 && $id !== $actor->id
            ? User::query()->find($id)
            : null;

        $request->attributes->set(self::MEMO, $user);

        return $user;
    }

    /** Omul după ale cărui drepturi se desenează pagina. */
    public static function effective(Request $request): ?User
    {
        return self::user($request) ?? $request->user();
    }
}
