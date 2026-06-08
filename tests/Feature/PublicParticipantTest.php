<?php

use App\Events\ParticipantSubmitted;
use App\Http\Requests\StorePublicParticipantRequest;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

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

test('a participant can submit with text only', function () {
    Event::fake([ParticipantSubmitted::class]);
    $session = Session::factory()->for(User::factory())->create();

    $response = $this->post("/c/{$session->public_token}/participants", [
        'name' => 'Ana',
        'text' => 'Comi uma pizza e tomei um suco',
    ]);

    $response->assertSessionHasNoErrors();
    $participant = SessionParticipant::first();
    expect($participant->name)->toBe('Ana')
        ->and($participant->text)->toBe('Comi uma pizza e tomei um suco')
        ->and($participant->audio_path)->toBeNull();
    Event::assertDispatched(ParticipantSubmitted::class);
});

test('a participant can submit with audio only', function () {
    Event::fake([ParticipantSubmitted::class]);
    Storage::fake('public');
    $session = Session::factory()->for(User::factory())->create();

    $response = $this->post("/c/{$session->public_token}/participants", [
        'name' => 'Bia',
        'audio' => UploadedFile::fake()->create('voz.webm', 80, 'audio/webm'),
        'audio_duration' => 45,
    ]);

    $response->assertSessionHasNoErrors();
    $participant = SessionParticipant::first();
    expect($participant->name)->toBe('Bia')
        ->and($participant->audio_path)->not->toBeNull()
        ->and($participant->audio_duration)->toBe(45);
    Storage::disk('public')->assertExists($participant->audio_path);
});

test('a participant submission requires text or audio', function () {
    $session = Session::factory()->for(User::factory())->create();

    $response = $this->post("/c/{$session->public_token}/participants", [
        'name' => 'Caio',
    ]);

    $response->assertSessionHasErrors('text');
    expect(SessionParticipant::count())->toBe(0);
});

test('duplicate names are rejected case-insensitively per session', function () {
    $session = Session::factory()->for(User::factory())->create();
    $session->participants()->create(['name' => 'Ana', 'text' => 'Pizza']);

    $response = $this->post("/c/{$session->public_token}/participants", [
        'name' => '  ana  ',
        'text' => 'Outra coisa',
    ]);

    $response->assertSessionHasErrors('name');
    expect(SessionParticipant::count())->toBe(1);
});

test('text longer than 256 characters is rejected', function () {
    $session = Session::factory()->for(User::factory())->create();

    $response = $this->post("/c/{$session->public_token}/participants", [
        'name' => 'Dora',
        'text' => str_repeat('a', 257),
    ]);

    $response->assertSessionHasErrors('text');
    expect(SessionParticipant::count())->toBe(0);
});

test('audio longer than 120 seconds is rejected', function () {
    Storage::fake('public');
    $session = Session::factory()->for(User::factory())->create();

    $response = $this->post("/c/{$session->public_token}/participants", [
        'name' => 'Eva',
        'audio' => UploadedFile::fake()->create('voz.webm', 80, 'audio/webm'),
        'audio_duration' => 121,
    ]);

    $response->assertSessionHasErrors('audio_duration');
    expect(SessionParticipant::count())->toBe(0);
});

test('the owner show page includes the public link and participants', function () {
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create();
    $session->participants()->create(['name' => 'Ana', 'text' => 'Pizza']);

    $this->actingAs($user)
        ->get("/sessions/{$session->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('session.public_url', route('public.sessions.show', $session->public_token))
            ->where('session.participants.0.name', 'Ana')
            ->where('session.participants.0.has_text', true)
            ->where('session.participants.0.has_audio', false)
        );
});
