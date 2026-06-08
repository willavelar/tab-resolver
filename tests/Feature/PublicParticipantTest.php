<?php

use App\Http\Requests\StorePublicParticipantRequest;
use App\Models\Session;
use App\Models\User;

test('a public token is generated automatically when a session is created', function () {
    $session = Session::factory()->for(User::factory())->create();

    expect($session->public_token)->toBeString()
        ->and(strlen($session->public_token))->toBe(32);
});

test('each session gets a distinct public token', function () {
    $a = Session::factory()->for(User::factory())->create();
    $b = Session::factory()->for(User::factory())->create();

    expect($a->public_token)->not->toBe($b->public_token);
});

test('a session has many participants ordered oldest first', function () {
    $session = Session::factory()->for(User::factory())->create();

    $first = $session->participants()->create(['name' => 'Ana', 'text' => 'Pizza']);
    $second = $session->participants()->create(['name' => 'Bia', 'text' => 'Suco']);

    $names = $session->fresh()->participants->pluck('name')->all();

    expect($names)->toBe(['Ana', 'Bia'])
        ->and($first->bill_session_id)->toBe($session->id)
        ->and($second->id)->not->toBe($first->id);
});

test('the public page opens without auth for a valid token', function () {
    $session = Session::factory()->for(User::factory())->create(['title' => 'Bar do Zé']);

    $response = $this->get("/c/{$session->public_token}");

    $response->assertOk();
});

test('an invalid public token returns 404', function () {
    $response = $this->get('/c/does-not-exist');

    $response->assertNotFound();
});

test('the participant request requires name and one of text or audio', function () {
    $rules = (new StorePublicParticipantRequest)->rules();

    expect($rules)->toHaveKeys(['name', 'text', 'audio', 'audio_duration'])
        ->and($rules['name'])->toContain('required')
        ->and($rules['text'])->toContain('required_without:audio')
        ->and($rules['text'])->toContain('max:256')
        ->and($rules['audio'])->toContain('required_without:text')
        ->and($rules['audio_duration'])->toContain('max:120');
});
