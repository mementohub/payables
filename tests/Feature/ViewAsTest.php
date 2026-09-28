<?php

use App\Models\User;
use App\Support\ViewAs;

/**
 * „Vezi ca” e o previzualizare, nu o schimbare de cont: cât ține, aplicația se
 * desenează după drepturile omului privit — meniul, dar și ușile. Altfel un
 * administrator se uită „prin ochii unui operațional” și tot Rapoartele lui
 * le vede.
 */
test('an administrator looking through an operational user sees their menu, not their own', function () {
    $admin = User::factory()->create(['roles' => [User::ROLE_ADMIN]]);
    $om = User::factory()->create(['roles' => [User::ROLE_OPERATIONAL]]);

    $this->actingAs($admin)->get("/approvals?as={$om->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.preview.name', $om->name)
            ->where('auth.user.id', $admin->id)
            ->where('auth.can.reports', false)
            ->where('auth.can.admin', false)
            ->where('auth.can.approvals', true));

    // Și ușile, nu doar meniul: altfel previzualizarea minte.
    $this->actingAs($admin)->withSession([ViewAs::SESSION => $om->id])
        ->get('/reports/pnl')
        ->assertForbidden();
});

test('the preview can be left from any page', function () {
    $admin = User::factory()->create(['roles' => [User::ROLE_ADMIN]]);
    $om = User::factory()->create(['roles' => [User::ROLE_OPERATIONAL]]);

    $this->actingAs($admin)->withSession([ViewAs::SESSION => $om->id])
        ->get('/reports/pnl?as=0')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('auth.preview', null));

    expect(session()->get(ViewAs::SESSION))->toBeNull();
});

test('only an administrator can look through somebody else', function () {
    $finance = User::factory()->create(['roles' => [User::ROLE_FINANCE]]);
    $boss = User::factory()->create(['roles' => [User::ROLE_TOP_MANAGEMENT]]);

    // Nici pornind previzualizarea, nici cu ea deja în sesiune nu se câștigă
    // dreptul altuia: Financiarul rămâne fără Rapoarte.
    $this->actingAs($finance)->get("/approvals?as={$boss->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.preview', null)->where('auth.can.reports', false));

    $this->actingAs($finance)->withSession([ViewAs::SESSION => $boss->id])
        ->get('/reports/pnl')
        ->assertForbidden();
});
