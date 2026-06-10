<?php

use App\Models\Session;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('bill-session.{sessionId}', function ($user, string $sessionId) {
    return Session::where('id', $sessionId)
        ->where('user_id', $user->id)
        ->exists();
});

// Public channel: only signals "reload", carries no per-person data.
Broadcast::channel('bill-session.{sessionId}.public', fn () => true);
