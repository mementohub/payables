<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ușa aplicației: fiecare intră în casa lui.
 *
 * Panoul principal e al celor care țin facturile companiei, așa că cine
 * n-are voie acolo era întâmpinat de un refuz în loc de lista lui de lucru.
 * Unde duce fiecare rol scrie pe `User::home()`, într-un singur loc.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        return $user === null
            ? redirect()->route('login')
            : redirect()->route($user->home());
    }
}
