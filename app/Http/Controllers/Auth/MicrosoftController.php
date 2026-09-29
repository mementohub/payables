<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class MicrosoftController extends Controller
{
    public function redirect(): SymfonyRedirectResponse
    {
        return Socialite::driver('microsoft')->redirect();
    }

    /** Peste atât, o poză nu mai e o adresă, ci un fișier pus în coloană. */
    private const MAX_AVATAR = 2048;

    public function callback(): RedirectResponse
    {
        try {
            $microsoftUser = Socialite::driver('microsoft')->user();
        } catch (ClientException $e) {
            logger()->error('Microsoft OAuth error', [
                'status' => $e->getResponse()->getStatusCode(),
                'body' => json_decode($e->getResponse()->getBody()->getContents(), true),
            ]);

            throw $e;
        }

        $user = User::query()
            ->where('microsoft_id', $microsoftUser->getId())
            ->orWhere('email', $microsoftUser->getEmail())
            ->first();

        if ($user === null) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Acest cont nu are acces. Contactează administratorul.']);
        }

        $user->forceFill([
            'name' => $microsoftUser->getName() ?: $user->name,
            'microsoft_id' => $microsoftUser->getId(),
            'avatar' => $this->avatarUrl($microsoftUser->getAvatar()) ?? $user->avatar,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user, remember: true);

        request()->session()->regenerate();

        return redirect()->intended(route($user->home()));
    }

    /**
     * Doar o adresă de poză, nu poza însăși.
     *
     * Microsoft întoarce uneori fotografia ca `data:image/jpeg;base64,…`, de
     * zeci de kilobytes. Aia nu încape în coloană (de aici un 500 la
     * autentificare, pentru oamenii cu poza mai mare) și, chiar dacă ar
     * încăpea, ar călători cu fiecare pagină, fiindcă `auth.user` se trimite
     * la fiecare cerere. Păstrăm doar adresele http(s) scurte; pentru rest
     * rămân inițialele.
     */
    private function avatarUrl(?string $avatar): ?string
    {
        $avatar = trim((string) $avatar);

        if ($avatar === '' || ! str_starts_with($avatar, 'http') || mb_strlen($avatar) > self::MAX_AVATAR) {
            return null;
        }

        return $avatar;
    }
}
