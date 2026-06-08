<?php

// tests/Feature/Auth/RouteProtectionTest.php

use App\Models\User;

it('redirects unauthenticated users from /dashboard to /login', function () {
    $this->get('/dashboard')
        ->assertRedirect('/login');
});

it('redirects unauthenticated users from /sessions/create to /login', function () {
    $this->get('/sessions/create')
        ->assertRedirect('/login');
});

it('allows authenticated users to access /dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});

it('allows authenticated users to access /sessions/create', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/sessions/create')
        ->assertOk();
});

it('redirects root / to login when unauthenticated', function () {
    $this->get('/')
        ->assertRedirect('/login');
});

it('redirects root / to dashboard when authenticated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect('/dashboard');
});
