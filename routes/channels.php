<?php

use App\Models\Session;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('bill-session.{sessionId}', function ($user, string $sessionId) {
    return Session::where('id', $sessionId)
        ->where('user_id', $user->id)
        ->exists();
});
