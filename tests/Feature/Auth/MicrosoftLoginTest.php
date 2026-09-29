<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Microsoft întoarce uneori fotografia de profil lipită în răspuns, ca
 * `data:image/jpeg;base64,…`, de zeci de kilobytes. O salvam ca atare: pentru
 * cine avea poza mai mare decât încăpea în coloană, autentificarea se termina
 * cu 500, iar pentru ceilalți poza pleca cu fiecare pagină.
 */
test('a photo pasted into the response does not break the login, and is not kept', function () {
    $user = User::factory()->create(['email' => 'om@christiantour.ro', 'avatar' => null, 'roles' => [User::ROLE_OPERATIONAL]]);

    Socialite::shouldReceive('driver->user')->andReturn((new SocialiteUser)->map([
        'id' => 'af474169-d859-418f-bd91-f17ce4213110',
        'name' => 'Om Cu Poză',
        'email' => $user->email,
        'avatar' => 'data:image/jpeg;base64,'.str_repeat('A', 80000),
    ]));

    $this->get('/auth/microsoft/callback')->assertRedirect('/approvals');

    expect($user->fresh()->avatar)->toBeNull()
        ->and($user->fresh()->microsoft_id)->toBe('af474169-d859-418f-bd91-f17ce4213110')
        ->and(auth()->id())->toBe($user->id);
});

test('a normal picture address is kept', function () {
    $user = User::factory()->create(['email' => 'altul@christiantour.ro', 'avatar' => null, 'roles' => [User::ROLE_OPERATIONAL]]);

    Socialite::shouldReceive('driver->user')->andReturn((new SocialiteUser)->map([
        'id' => '1234',
        'name' => 'Alt Om',
        'email' => $user->email,
        'avatar' => 'https://graph.microsoft.com/v1.0/me/photo/value.jpg',
    ]));

    $this->get('/auth/microsoft/callback')->assertRedirect();

    expect($user->fresh()->avatar)->toBe('https://graph.microsoft.com/v1.0/me/photo/value.jpg');
});
