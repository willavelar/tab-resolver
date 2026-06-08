<?php

namespace App\Http\Controllers;

use App\Events\ParticipantSubmitted;
use App\Http\Requests\StorePublicParticipantRequest;
use App\Models\Session;
use Illuminate\Http\RedirectResponse;
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

    public function store(StorePublicParticipantRequest $request, string $token): RedirectResponse
    {
        $session = Session::where('public_token', $token)->firstOrFail();

        $audioPath = null;
        if ($request->hasFile('audio')) {
            $audioPath = $request->file('audio')->store('participant-audios', 'public');
        }

        $participant = $session->participants()->create([
            'name' => $request->validated('name'),
            'text' => $request->validated('text'),
            'audio_path' => $audioPath,
            'audio_duration' => $request->validated('audio_duration'),
        ]);

        event(new ParticipantSubmitted(
            sessionId: $session->id,
            participantId: $participant->id,
            name: $participant->name,
            hasText: filled($participant->text),
            hasAudio: filled($participant->audio_path),
            text: $participant->text,
            audioUrl: $audioPath ? Storage::disk('public')->url($audioPath) : null,
            createdAt: $participant->created_at->format('d/m/Y H:i'),
        ));

        return back()->with('success', 'Enviado! Obrigado por participar.');
    }
}
