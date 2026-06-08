<?php

namespace App\Http\Controllers;

use App\Models\Session;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PublicSessionController extends Controller
{
    public function show(string $token): Response
    {
        $session = Session::where('public_token', $token)->firstOrFail();

        return Inertia::render('Public/Session', [
            'session' => [
                'title' => $session->title,
                'image_url' => Storage::disk('public')->url($session->image_path),
                'token' => $session->public_token,
            ],
        ]);
    }
}
