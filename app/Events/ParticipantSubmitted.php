<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipantSubmitted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $participantId,
        public string $name,
        public bool $hasText,
        public bool $hasAudio,
        public ?string $text,
        public ?string $audioUrl,
        public string $createdAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('bill-session.'.$this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'participant.submitted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->participantId,
            'name' => $this->name,
            'has_text' => $this->hasText,
            'has_audio' => $this->hasAudio,
            'text' => $this->text,
            'audio_url' => $this->audioUrl,
            'created_at' => $this->createdAt,
        ];
    }
}
