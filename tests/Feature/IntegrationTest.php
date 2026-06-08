<?php

use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('redirects guests away from the integrations page', function () {
    $this->get('/integrations')->assertRedirect('/login');
});

it('renders the integrations page for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/integrations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Integrations/Edit')
            ->has('has_api_key')
            ->has('model')
        );
});

it('does not leak the real api key to the frontend', function () {
    Integration::create([
        'provider' => 'anthropic',
        'api_key' => 'sk-ant-secret-9876',
        'model' => 'claude-sonnet-4-5-20250929',
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/integrations')
        ->assertInertia(fn ($page) => $page
            ->where('has_api_key', true)
            ->where('api_key_preview', '••••••••9876')
            ->missing('api_key')
        );
});

it('stores the api key encrypted and the model', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/integrations', [
            'model' => 'claude-sonnet-4-5-20250929',
            'api_key' => 'sk-ant-brandnew-0001',
        ])
        ->assertRedirect('/integrations');

    $raw = DB::table('integrations')->where('provider', 'anthropic')->value('api_key');
    expect($raw)->not->toBe('sk-ant-brandnew-0001');
    expect(Crypt::decryptString($raw))->toBe('sk-ant-brandnew-0001');

    $integration = Integration::current();
    expect($integration->model)->toBe('claude-sonnet-4-5-20250929');
});

it('keeps the existing api key when api_key is left blank', function () {
    Integration::create([
        'provider' => 'anthropic',
        'api_key' => 'sk-ant-keepme-5555',
        'model' => 'old-model',
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/integrations', [
            'model' => 'new-model',
            'api_key' => '',
        ])
        ->assertRedirect('/integrations');

    $integration = Integration::current();
    expect($integration->api_key)->toBe('sk-ant-keepme-5555');
    expect($integration->model)->toBe('new-model');
});

it('requires the model field', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/integrations', ['model' => '', 'api_key' => 'x'])
        ->assertSessionHasErrors('model');
});
