<?php

namespace App\Http\Controllers;

use App\Enums\ExtractionStatus;
use App\Events\ParticipantSubmitted;
use App\Http\Requests\StorePublicParticipantRequest;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Services\Receipt\ReceiptSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PublicSessionController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $session = Session::where('public_token', $token)->firstOrFail();

        $session->load('items');

        $existing = $this->existingParticipant($request, $session);

        return Inertia::render('Public/Session', [
            'session' => [
                'title' => $session->title,
                'image_url' => Storage::disk('public')->url($session->image_path),
                'token' => $session->public_token,
                'status' => $session->status->value,
                'subtotal' => $session->subtotal,
                'service_charge' => $session->service_charge,
                'service_charge_percentage' => $session->service_charge_percentage,
                'total' => $session->total,
                'items' => $session->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                    'category' => $item->category?->value,
                ]),
                'summary_markdown' => $session->status === ExtractionStatus::Completed
                    ? ReceiptSummary::for($session)
                    : null,
            ],
            'alreadySubmitted' => $existing !== null,
            'submittedName' => $existing?->name,
        ]);
    }

    public function store(StorePublicParticipantRequest $request, string $token): RedirectResponse
    {
        Log::info('[Controller][PublicSessionController][store] Inicio da execusão.', [
            'token' => $token,
            'name' => $request->validated('name'),
        ]);

        $session = Session::where('public_token', $token)->firstOrFail();

        // Idempotent: a device that already submitted just sees the sent state.
        if ($this->existingParticipant($request, $session) !== null) {
            Log::info('[Controller][PublicSessionController][store] Participante já havia enviado (idempotente). Fim da execusão.', [
                'session_id' => $session->id,
            ]);

            return back()->with('success', 'Enviado! Obrigado por participar.');
        }

        $submitterToken = $request->cookie('tr_pid') ?: Str::random(40);

        $audioPath = null;
        if ($request->hasFile('audio')) {
            $audioPath = $request->file('audio')->store('participant-audios', 'public');
            Log::info('[Controller][PublicSessionController][store] Áudio do participante armazenado.', [
                'session_id' => $session->id,
                'audio_path' => $audioPath,
            ]);
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

        Log::info('[Controller][PublicSessionController][store] Participante registrado e evento disparado. Fim da execusão.', [
            'session_id' => $session->id,
            'participant_id' => $participant->id,
            'has_audio' => filled($audioPath),
        ]);

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
