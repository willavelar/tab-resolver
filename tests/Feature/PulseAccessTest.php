<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids non-admin users from viewing the pulse dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/pulse')
        ->assertForbidden();
});

it('allows admin users to view the pulse dashboard', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('/pulse')
        ->assertOk();
});
