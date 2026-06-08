<?php

use App\Enums\ExtractionStatus;
use App\Events\ReceiptExtractionUpdated;
use App\Models\Session;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;

test('the event broadcasts on the private session channel', function () {
    $session = Session::factory()->for(User::factory())->create();

    $event = new ReceiptExtractionUpdated($session->id, ExtractionStatus::Processing->value, null);

    $channels = $event->broadcastOn();

    expect($channels)->toBeInstanceOf(PrivateChannel::class)
        ->and($channels->name)->toBe('private-bill-session.'.$session->id);
});

test('the owner is authorized on the session channel and others are not', function () {
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
})->skip('broadcasting auth route enabled in Task 8 (Reverb setup)');
