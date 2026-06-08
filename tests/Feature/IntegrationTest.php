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

it('forbids non-admin users from viewing the integrations page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/integrations')
        ->assertForbidden();
});

it('forbids non-admin users from updating the integration', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/integrations', [
            'receipt_model' => 'gpt-4o-mini',
            'audio_model' => 'whisper-1',
            'api_key' => 'sk-nope-0000',
        ])
        ->assertForbidden();

    expect(Integration::query()->count())->toBe(0);
});

it('renders the integrations page for authenticated users', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('/integrations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Integrations/Edit')
            ->has('has_api_key')
            ->has('receipt_model')
            ->has('audio_model')
        );
});

it('does not leak the real api key to the frontend', function () {
    Integration::create([
        'provider' => 'openai',
        'api_key' => 'sk-secret-9876',
        'receipt_model' => 'gpt-4o-mini',
        'audio_model' => 'whisper-1',
    ]);
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('/integrations')
        ->assertInertia(fn ($page) => $page
            ->where('has_api_key', true)
            ->where('api_key_preview', '••••••••9876')
            ->missing('api_key')
        );
});

it('stores the api key encrypted and both models', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->patch('/integrations', [
            'receipt_model' => 'gpt-4o',
            'audio_model' => 'gpt-4o-transcribe',
            'api_key' => 'sk-brandnew-0001',
        ])
        ->assertRedirect('/integrations');

    $raw = DB::table('integrations')->where('provider', 'openai')->value('api_key');
    expect($raw)->not->toBe('sk-brandnew-0001');
    expect(Crypt::decryptString($raw))->toBe('sk-brandnew-0001');

    $integration = Integration::current();
    expect($integration->receipt_model)->toBe('gpt-4o');
    expect($integration->audio_model)->toBe('gpt-4o-transcribe');
});

it('keeps the existing api key when api_key is left blank', function () {
    Integration::create([
        'provider' => 'openai',
        'api_key' => 'sk-keepme-5555',
        'receipt_model' => 'gpt-4o-mini',
        'audio_model' => 'whisper-1',
    ]);
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->patch('/integrations', [
            'receipt_model' => 'gpt-4o',
            'audio_model' => 'whisper-1',
            'api_key' => '',
        ])
        ->assertRedirect('/integrations');

    $integration = Integration::current();
    expect($integration->api_key)->toBe('sk-keepme-5555');
    expect($integration->receipt_model)->toBe('gpt-4o');
});

it('requires the receipt_model field', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->patch('/integrations', [
            'receipt_model' => '',
            'audio_model' => 'whisper-1',
            'api_key' => 'x',
        ])
        ->assertSessionHasErrors('receipt_model');
});

it('requires the audio_model field', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->patch('/integrations', [
            'receipt_model' => 'gpt-4o-mini',
            'audio_model' => '',
            'api_key' => 'x',
        ])
        ->assertSessionHasErrors('audio_model');
});
