<?php

use App\Events\ParticipantSubmitted;
use App\Models\Session;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;

test('the participant event broadcasts on the private session channel', function () {
    $session = Session::factory()->for(User::factory())->create();

    $event = new ParticipantSubmitted(
        sessionId: $session->id,
        participantId: 'part_123',
        name: 'Ana',
        hasText: true,
        hasAudio: false,
        text: 'Pizza',
        audioUrl: null,
        createdAt: '08/06/2026 20:00',
    );

    $channel = $event->broadcastOn();

    expect($channel)->toBeInstanceOf(PrivateChannel::class)
        ->and($channel->name)->toBe('private-bill-session.'.$session->id)
        ->and($event->broadcastAs())->toBe('participant.submitted')
        ->and($event->broadcastWith())->toMatchArray([
            'id' => 'part_123',
            'name' => 'Ana',
            'has_text' => true,
            'has_audio' => false,
            'text' => 'Pizza',
            'audio_url' => null,
        ]);
});
