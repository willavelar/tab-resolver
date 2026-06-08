<?php

// app/Http/Controllers/SessionController.php

namespace App\Http\Controllers;

use App\Enums\ExtractionStatus;
use App\Events\ReceiptExtractionUpdated;
use App\Http\Requests\ClarifyExtractionRequest;
use App\Http\Requests\StoreSessionRequest;
use App\Jobs\ExtractReceiptItems;
use App\Models\Session;
use App\Services\Receipt\ReceiptSummary;
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

    public function extract(Session $session): RedirectResponse
    {
        abort_unless($session->user_id === auth()->id(), 403);
        abort_if(
            in_array($session->status, [
                ExtractionStatus::Processing,
                ExtractionStatus::Completed,
                ExtractionStatus::NeedsClarification,
            ], true),
            403,
        );

        $session->update([
            'status' => ExtractionStatus::Processing,
            'failure_reason' => null,
            'clarifications' => null,
        ]);

        event(new ReceiptExtractionUpdated($session->id, ExtractionStatus::Processing->value));

        ExtractReceiptItems::dispatch($session);

        return redirect()->route('sessions.show', $session);
    }

    public function clarify(ClarifyExtractionRequest $request, Session $session): RedirectResponse
    {
        abort_unless($session->user_id === auth()->id(), 403);
        abort_unless($session->status === ExtractionStatus::NeedsClarification, 403);

        $clarifications = $session->clarifications ?? [];
        $answered = $clarifications['answered'] ?? [];
        $answers = $request->validated('answers');

        foreach ($clarifications['pending'] ?? [] as $question) {
            if (! array_key_exists($question['id'], $answers)) {
                continue;
            }

            $answered[] = [
                'question' => $question['prompt'],
                'answer' => $answers[$question['id']],
            ];
        }

        $session->update([
            'status' => ExtractionStatus::Processing,
            'clarifications' => [
                'round' => ($clarifications['round'] ?? 0) + 1,
                'answered' => $answered,
                'pending' => [],
            ],
        ]);

        event(new ReceiptExtractionUpdated($session->id, ExtractionStatus::Processing->value));

        ExtractReceiptItems::dispatch($session);

        return redirect()->route('sessions.show', $session);
    }

    public function show(Session $session): Response
    {
        abort_unless($session->user_id === auth()->id(), 403);

        $session->load(['items', 'participants']);

        return Inertia::render('Sessions/Show', [
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'image_url' => Storage::disk('public')->url($session->image_path),
                'created_at' => $session->created_at->format('d/m/Y H:i'),
                'status' => $session->status->value,
                'failure_reason' => $session->failure_reason,
                'subtotal' => $session->subtotal,
                'service_charge' => $session->service_charge,
                'total' => $session->total,
                'service_charge_percentage' => $session->service_charge_percentage,
                'clarifications' => $session->clarifications,
                'summary_markdown' => $session->status === ExtractionStatus::Completed
                    ? ReceiptSummary::for($session)
                    : null,
                'items' => $session->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                    'category' => $item->category?->value,
                ]),
                'public_token' => $session->public_token,
                'public_url' => route('public.sessions.show', $session->public_token),
                'participants' => $session->participants->map(fn ($participant) => [
                    'id' => $participant->id,
                    'name' => $participant->name,
                    'has_text' => filled($participant->text),
                    'has_audio' => filled($participant->audio_path),
                    'text' => $participant->text,
                    'audio_url' => $participant->audio_path
                        ? Storage::disk('public')->url($participant->audio_path)
                        : null,
                    'created_at' => $participant->created_at->format('d/m/Y H:i'),
                ]),
            ],
        ]);
    }
}
