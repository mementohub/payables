<?php

use App\Models\Department;
use App\Models\User;

beforeEach(function () {
    $this->department = Department::query()->whereNotNull('code')->first();
    $this->admin = User::factory()->withRoles('admin')->create();
});

test('an admin names the head of a department, and the head joins it', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->put('/departments/'.$this->department->id.'/head', ['user_id' => $user->id])
        ->assertRedirect();

    expect($this->department->fresh()->head_user_id)->toBe($user->id)
        // Șeful aprobă pentru departamentul lui, deci intră și în el.
        ->and($this->department->members()->whereKey($user->id)->exists())->toBeTrue();
});

test('taking the head out of the department leaves it without one', function () {
    $user = User::factory()->create();
    $this->department->members()->attach($user);
    $this->department->update(['head_user_id' => $user->id]);

    $this->actingAs($this->admin)
        ->delete('/departments/'.$this->department->id.'/members/'.$user->id)
        ->assertRedirect();

    expect($this->department->fresh()->head_user_id)->toBeNull();
});

test('only an admin names the head', function () {
    $finance = User::factory()->withRoles('finance')->create();

    $this->actingAs($finance)
        ->put('/departments/'.$this->department->id.'/head', ['user_id' => $finance->id])
        ->assertForbidden();

    expect($this->department->fresh()->head_user_id)->toBeNull();
});
