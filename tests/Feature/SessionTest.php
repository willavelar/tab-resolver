<?php

use App\Enums\ExtractionStatus;
use App\Enums\ItemCategory;
use App\Models\Session;
use App\Models\SessionItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('guest cannot create a session', function () {
    $response = $this->post('/sessions', [
        'title' => 'Jantar de quinta',
        'image' => UploadedFile::fake()->create('conta.jpg', 100, 'image/jpeg'),
    ]);

    $response->assertRedirect('/login');
});

test('authenticated user can create a session with a receipt photo', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $image = UploadedFile::fake()->create('conta.jpg', 100, 'image/jpeg');

    $response = $this
        ->actingAs($user)
        ->post('/sessions', [
            'title' => 'Jantar de quinta',
            'image' => $image,
        ]);

    $session = Session::first();

    expect($session)->not->toBeNull();
    expect($session->title)->toBe('Jantar de quinta');
    expect($session->user_id)->toBe($user->id);

    Storage::disk('public')->assertExists($session->image_path);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect("/sessions/{$session->id}");
});

test('title is required to create a session', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post('/sessions', [
            'title' => '',
            'image' => UploadedFile::fake()->create('conta.jpg', 100, 'image/jpeg'),
        ]);

    $response->assertSessionHasErrors('title');
    expect(Session::count())->toBe(0);
});

test('a receipt photo is required to create a session', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post('/sessions', [
            'title' => 'Jantar de quinta',
        ]);

    $response->assertSessionHasErrors('image');
    expect(Session::count())->toBe(0);
});

test('the receipt photo must be an accepted image type', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post('/sessions', [
            'title' => 'Jantar de quinta',
            'image' => UploadedFile::fake()->create('conta.pdf', 100, 'application/pdf'),
        ]);

    $response->assertSessionHasErrors('image');
    expect(Session::count())->toBe(0);
});

test('the owner can view their session', function () {
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create();

    $response = $this
        ->actingAs($user)
        ->get("/sessions/{$session->id}");

    $response->assertOk();
});

test('a user cannot view a session that is not theirs', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $session = Session::factory()->for($owner)->create();

    $response = $this
        ->actingAs($intruder)
        ->get("/sessions/{$session->id}");

    $response->assertForbidden();
});

test('guest cannot view a session', function () {
    $session = Session::factory()->for(User::factory())->create();

    $response = $this->get("/sessions/{$session->id}");

    $response->assertRedirect('/login');
});

test('the show page exposes category, tip percentage, clarifications and summary', function () {
    $owner = User::factory()->create();
    $session = Session::factory()->for($owner)->create([
        'status' => ExtractionStatus::Completed,
        'subtotal' => 50,
        'service_charge' => 5,
        'service_charge_percentage' => 10,
        'total' => 55,
    ]);
    SessionItem::create(['bill_session_id' => $session->id, 'name' => 'Heineken', 'quantity' => 1, 'unit_price' => 9.90, 'total_price' => 9.90, 'category' => ItemCategory::Drink, 'position' => 1]);

    $this->actingAs($owner)
        ->get("/sessions/{$session->id}")
        ->assertInertia(fn ($page) => $page
            ->component('Sessions/Show')
            ->where('session.service_charge_percentage', fn ($v) => (float) $v === 10.0)
            ->where('session.items.0.category', 'drink')
            ->where('session.summary_markdown', fn ($v) => str_contains((string) $v, '## Bebida'))
        );
});

test('dashboard shows the authenticated user sessions with title and date', function () {
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create([
        'title' => 'Churrasco de sábado',
        'status' => ExtractionStatus::Completed,
    ]);

    $this
        ->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('sessions', 1)
            ->where('sessions.0.id', $session->id)
            ->where('sessions.0.title', 'Churrasco de sábado')
            ->where('sessions.0.status', 'completed')
            ->where('sessions.0.created_at', $session->created_at->format('d/m/Y H:i'))
        );
});

test('dashboard does not show sessions owned by other users', function () {
    $user = User::factory()->create();
    Session::factory()->create(['title' => 'Sessão alheia']);

    $this
        ->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('sessions', 0)
        );
});

it('defaults others_shared to false and casts it to boolean', function () {
    $session = Session::factory()->for(User::factory())->create();

    expect($session->fresh()->others_shared)->toBeFalse();
});
