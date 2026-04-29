<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
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

    public function callback(): RedirectResponse
    {
        $microsoftUser = Socialite::driver('microsoft')->user();

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
            'avatar' => $microsoftUser->getAvatar() ?: $user->avatar,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user, remember: true);

        request()->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
