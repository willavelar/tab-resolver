<?php

use App\Enums\ExtractionStatus;
use App\Events\ReceiptExtractionUpdated;
use App\Models\Session;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;

test('the event broadcasts on the private session channel', function () {
    $session = Session::factory()->for(User::factory())->create();

    $event = new ReceiptExtractionUpdated($session->id, ExtractionStatus::Processing->value, null);

    $channels = $event->broadcastOn();

    expect($channels)->toBeInstanceOf(PrivateChannel::class)
        ->and($channels->name)->toBe('private-bill-session.'.$session->id);
});

test('the owner is authorized on the session channel and others are not', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'local-key',
        'broadcasting.connections.reverb.secret' => 'local-secret',
        'broadcasting.connections.reverb.app_id' => 'tabresolver',
    ]);

    // Forget the null driver so a fresh reverb driver is resolved with our key/secret.
    Broadcast::forgetDrivers();

    // Re-register the channel callback on the new reverb driver.
    Broadcast::channel('bill-session.{sessionId}', function ($user, string $sessionId) {
        return Session::where('id', $sessionId)
            ->where('user_id', $user->id)
            ->exists();
    });

    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $session = Session::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-bill-session.'.$session->id,
            'socket_id' => '1234.5678',
        ])
        ->assertOk();

    $this->actingAs($intruder)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-bill-session.'.$session->id,
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});
