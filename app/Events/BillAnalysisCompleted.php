<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BillAnalysisCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $sessionId) {}

    public function broadcastOn(): Channel
    {
        return new Channel('bill-session.'.$this->sessionId.'.public');
    }

    public function broadcastAs(): string
    {
        return 'analysis.completed';
    }
}
