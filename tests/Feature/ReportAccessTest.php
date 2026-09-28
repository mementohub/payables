<?php

use App\Models\User;

/**
 * Cifrele companiei — venit, marjă, profit, trezorerie — sunt ale Top
 * Management-ului și ale administratorilor. Regula nu ține doar de meniu:
 * paginile, exporturile, asistentul care interoghează baza și graficul de
 * trezorerie de pe panoul principal duc aceleași cifre.
 */
$rapoarte = [
    '/reports/pnl',
    '/reports/opex',
    '/reports/cash-flow',
    '/ai-assistant',
];

test('top management and admins reach the reports', function () use ($rapoarte) {
    foreach ([User::ROLE_TOP_MANAGEMENT, User::ROLE_ADMIN] as $role) {
        $user = User::factory()->create(['roles' => [$role]]);

        foreach ($rapoarte as $ruta) {
            $this->actingAs($user)->get($ruta)->assertSuccessful();
        }
    }
});

test('nobody else does, whatever they type in the address bar', function () use ($rapoarte) {
    foreach ([[User::ROLE_FINANCE], [User::ROLE_TREASURY], []] as $roles) {
        $user = User::factory()->create(['roles' => $roles]);

        foreach ($rapoarte as $ruta) {
            $this->actingAs($user)->get($ruta)->assertForbidden();
        }
    }
});

test('the treasury chart on the dashboard follows the same rule', function () {
    $boss = User::factory()->create(['roles' => [User::ROLE_TOP_MANAGEMENT]]);
    $finance = User::factory()->create(['roles' => [User::ROLE_FINANCE]]);

    $this->actingAs($boss)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canSeeCashflow', true));

    $this->actingAs($finance)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canSeeCashflow', false));
});
