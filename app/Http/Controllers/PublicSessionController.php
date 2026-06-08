<?php

namespace App\Http\Controllers;

use App\Events\ParticipantSubmitted;
use App\Http\Requests\StorePublicParticipantRequest;
use App\Models\Session;
use App\Models\SessionParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PublicSessionController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $session = Session::where('public_token', $token)->firstOrFail();

        $existing = $this->existingParticipant($request, $session);

        return Inertia::render('Public/Session', [
            'session' => [
                'title' => $session->title,
                'image_url' => Storage::disk('public')->url($session->image_path),
                'token' => $session->public_token,
            ],
            'alreadySubmitted' => $existing !== null,
            'submittedName' => $existing?->name,
        ]);
    }

    public function store(StorePublicParticipantRequest $request, string $token): RedirectResponse
    {
        $session = Session::where('public_token', $token)->firstOrFail();

        // Idempotent: a device that already submitted just sees the sent state.
        if ($this->existingParticipant($request, $session) !== null) {
            return back()->with('success', 'Enviado! Obrigado por participar.');
        }

        $submitterToken = $request->cookie('tr_pid') ?: Str::random(40);

        $audioPath = null;
        if ($request->hasFile('audio')) {
            $audioPath = $request->file('audio')->store('participant-audios', 'public');
        }

        $participant = $session->participants()->create([
            'name' => $request->validated('name'),
            'submitter_token' => $submitterToken,
            'text' => $request->validated('text'),
            'audio_path' => $audioPath,
            'audio_duration' => $request->validated('audio_duration'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
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

        return back()
            ->with('success', 'Enviado! Obrigado por participar.')
            ->withCookie(Cookie::forever('tr_pid', $submitterToken));
    }

    private function existingParticipant(Request $request, Session $session): ?SessionParticipant
    {
        $token = $request->cookie('tr_pid');

        if (! $token) {
            return null;
        }

        return $session->participants()
            ->where('submitter_token', $token)
            ->first();
    }
}
