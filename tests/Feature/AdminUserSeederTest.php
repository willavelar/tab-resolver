<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates an admin user from config', function () {
    config([
        'admin.name' => 'Boss',
        'admin.email' => 'admin@example.com',
        'admin.password' => 'secret-pass-123',
    ]);

    $this->seed(AdminUserSeeder::class);

    $user = User::where('email', 'admin@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->is_admin)->toBeTrue();
    expect($user->name)->toBe('Boss');
    expect($user->email_verified_at)->not->toBeNull();
    expect(Hash::check('secret-pass-123', $user->password))->toBeTrue();
});

it('skips seeding when admin credentials are missing', function () {
    config(['admin.email' => null, 'admin.password' => null]);

    $this->seed(AdminUserSeeder::class);

    expect(User::count())->toBe(0);
});

it('promotes an existing user to admin without duplicating', function () {
    User::factory()->create(['email' => 'existing@example.com', 'is_admin' => false]);

    config([
        'admin.name' => 'Admin',
        'admin.email' => 'existing@example.com',
        'admin.password' => 'new-pass-456',
    ]);

    $this->seed(AdminUserSeeder::class);

    expect(User::where('email', 'existing@example.com')->count())->toBe(1);
    expect(User::where('email', 'existing@example.com')->first()->is_admin)->toBeTrue();
});
