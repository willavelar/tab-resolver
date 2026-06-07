<?php

// app/Http/Controllers/SessionController.php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSessionRequest;
use App\Models\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Sessions/Create');
    }

    public function store(StoreSessionRequest $request): RedirectResponse
    {
        $path = $request->file('image')->store('receipts', 'public');

        $session = $request->user()->sessions()->create([
            'title' => $request->validated('title'),
            'image_path' => $path,
        ]);

        return redirect()->route('sessions.show', $session);
    }

    public function show(Session $session): Response
    {
        abort_unless($session->user_id === auth()->id(), 403);

        return Inertia::render('Sessions/Show', [
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'image_url' => Storage::disk('public')->url($session->image_path),
                'created_at' => $session->created_at->format('d/m/Y H:i'),
            ],
        ]);
    }
}
