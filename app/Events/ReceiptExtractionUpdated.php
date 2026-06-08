<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReceiptExtractionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $status,
        public ?string $failureReason = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('bill-session.'.$this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'extraction.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'status' => $this->status,
            'failureReason' => $this->failureReason,
        ];
    }
}
