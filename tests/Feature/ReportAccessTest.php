<?php

use App\Models\Department;
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

/**
 * Harta rolurilor, așa cum a fost cerută:
 *   Administrator   tot;
 *   Top Management  tot; el aprobă final;
 *   Financiar       tot în afară de Rapoarte; contestă și mută, nu aprobă;
 *   Trezorerie      rulaje, e-Facturi, verificări, extrase;
 *   Operațional     doar Aprobări, pentru departamentul lui.
 */
dataset('harta', [
    'administrator' => [User::ROLE_ADMIN, ['dashboard', 'approvals', 'payment-runs', 'invoices', 'routing', 'reports']],
    'top management' => [User::ROLE_TOP_MANAGEMENT, ['dashboard', 'approvals', 'payment-runs', 'invoices', 'routing', 'reports']],
    'financiar' => [User::ROLE_FINANCE, ['dashboard', 'approvals', 'payment-runs', 'invoices', 'routing']],
    'trezorerie' => [User::ROLE_TREASURY, ['payment-runs']],
    'operational' => [User::ROLE_OPERATIONAL, ['approvals']],
]);

test('each role reaches its own part of the application', function (string $role, array $allowed) {
    $pagini = [
        'dashboard' => '/dashboard',
        'approvals' => '/approvals',
        'payment-runs' => '/payment-runs',
        'invoices' => '/invoices/received',
        'routing' => '/routing',
        'reports' => '/reports/pnl',
    ];

    $user = User::factory()->create(['roles' => [$role]]);

    foreach ($pagini as $nume => $ruta) {
        $raspuns = $this->actingAs($user)->get($ruta);

        in_array($nume, $allowed, true)
            ? $raspuns->assertSuccessful()
            : $raspuns->assertForbidden();
    }
})->with('harta');

test('treasury keeps what it needs to pay, and nothing more', function () {
    $treasury = User::factory()->create(['roles' => [User::ROLE_TREASURY]]);

    foreach (['/payment-runs', '/e-invoices', '/payment-checks', '/bank-statements'] as $ruta) {
        $this->actingAs($treasury)->get($ruta)->assertSuccessful();
    }

    foreach (['/approvals', '/invoices/received', '/suppliers', '/routing'] as $ruta) {
        $this->actingAs($treasury)->get($ruta)->assertForbidden();
    }
});

test('an operational user brings a colleague onto their own department', function () {
    $department = Department::query()->create(['name' => 'Charters', 'code' => 'chart-team']);
    $user = User::factory()->create(['roles' => [User::ROLE_OPERATIONAL]]);
    $user->departments()->attach($department->id);

    $this->actingAs($user)->post('/team', ['emails' => 'coleg.nou@christiantour.ro'])->assertRedirect();

    $colegul = User::query()->where('email', 'coleg.nou@christiantour.ro')->sole();

    expect($colegul->roles)->toBe([User::ROLE_OPERATIONAL])
        ->and($colegul->departmentIds())->toBe([$department->id]);

    // Nu poate da alt rol și nu poate autoriza pe alt departament decât al lui.
    $this->actingAs($colegul)->get('/team')->assertOk();
    $this->actingAs($colegul)->get('/reports/pnl')->assertForbidden();
});

test('someone without a department has no team to build', function () {
    $this->actingAs(User::factory()->create(['roles' => [User::ROLE_TREASURY]]))
        ->get('/team')
        ->assertForbidden();
});
