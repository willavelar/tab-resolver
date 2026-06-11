<?php

// app/Http/Controllers/SessionController.php

namespace App\Http\Controllers;

use App\Enums\AnalysisStatus;
use App\Enums\ExtractionStatus;
use App\Events\ReceiptAnalysisUpdated;
use App\Events\ReceiptExtractionUpdated;
use App\Http\Requests\ClarifyAnalysisRequest;
use App\Http\Requests\ClarifyExtractionRequest;
use App\Http\Requests\StoreSessionRequest;
use App\Http\Requests\UpdateFoodSharedRequest;
use App\Jobs\AnalyzeBill;
use App\Jobs\ExtractReceiptItems;
use App\Models\Session;
use App\Services\Receipt\ReceiptSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function index(): Response
    {
        $sessions = auth()->user()->sessions()
            ->latest()
            ->get(['id', 'title', 'status', 'created_at'])
            ->map(fn (Session $session) => [
                'id' => $session->id,
                'title' => $session->title,
                'status' => $session->status->value,
                'created_at' => $session->created_at->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Dashboard', [
            'sessions' => $sessions,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sessions/Create');
    }

    public function store(StoreSessionRequest $request): RedirectResponse
    {
        Log::info('[Controller][SessionController][store] Inicio da execusão.', [
            'user_id' => $request->user()->id,
            'title' => $request->validated('title'),
        ]);

        $path = $request->file('image')->store('receipts', 'public');

        Log::info('[Controller][SessionController][store] Imagem do recibo armazenada.', [
            'image_path' => $path,
        ]);

        $session = $request->user()->sessions()->create([
            'title' => $request->validated('title'),
            'image_path' => $path,
        ]);

        Log::info('[Controller][SessionController][store] Fim da execusão.', [
            'session_id' => $session->id,
        ]);

        return redirect()->route('sessions.show', $session);
    }

    public function extract(Session $session): RedirectResponse
    {
        Log::info('[Controller][SessionController][extract] Inicio da execusão.', [
            'session_id' => $session->id,
            'status' => $session->status->value,
        ]);

        if ($session->user_id !== auth()->id()) {
            Log::warning('[Controller][SessionController][extract] Acesso negado: usuário não é dono da sessão.', [
                'session_id' => $session->id,
                'user_id' => auth()->id(),
            ]);
            abort(403);
        }

        $blockedStatuses = [
            ExtractionStatus::Processing,
            ExtractionStatus::Completed,
            ExtractionStatus::NeedsClarification,
        ];

        if (in_array($session->status, $blockedStatuses, true)) {
            Log::warning('[Controller][SessionController][extract] Extração bloqueada: status atual não permite reprocessar.', [
                'session_id' => $session->id,
                'status' => $session->status->value,
            ]);
            abort(403);
        }

        $session->update([
            'status' => ExtractionStatus::Processing,
            'failure_reason' => null,
            'clarifications' => null,
        ]);

        event(new ReceiptExtractionUpdated($session->id, ExtractionStatus::Processing->value));

        ExtractReceiptItems::dispatch($session);

        Log::info('[Controller][SessionController][extract] Job de extração despachado. Fim da execusão.', [
            'session_id' => $session->id,
        ]);

        return redirect()->route('sessions.show', $session);
    }

    /**
     * Rescue a session orphaned in "processing" when the queue dies without ever
     * running the job's failed() (worker killed, SIGKILL/OOM, queue down). Called
     * by the frontend watchdog once it has waited longer than the job could
     * reasonably take. Idempotent: only acts while still processing.
     */
    public function markExtractionTimedOut(Session $session): RedirectResponse
    {
        if ($session->user_id !== auth()->id()) {
            Log::warning('[Controller][SessionController][markExtractionTimedOut] Acesso negado: usuário não é dono da sessão.', [
                'session_id' => $session->id,
                'user_id' => auth()->id(),
            ]);
            abort(403);
        }

        if ($session->status === ExtractionStatus::Processing) {
            $reason = 'O processamento demorou demais e não pôde ser concluído. Tente novamente.';

            $session->update([
                'status' => ExtractionStatus::Failed,
                'failure_reason' => $reason,
            ]);

            event(new ReceiptExtractionUpdated($session->id, ExtractionStatus::Failed->value, $reason));

            Log::warning('[Controller][SessionController][markExtractionTimedOut] Sessão presa em processamento marcada como falha.', [
                'session_id' => $session->id,
            ]);
        }

        return redirect()->route('sessions.show', $session);
    }

    public function clarify(ClarifyExtractionRequest $request, Session $session): RedirectResponse
    {
        Log::info('[Controller][SessionController][clarify] Inicio da execusão.', [
            'session_id' => $session->id,
            'status' => $session->status->value,
        ]);

        if ($session->user_id !== auth()->id()) {
            Log::warning('[Controller][SessionController][clarify] Acesso negado: usuário não é dono da sessão.', [
                'session_id' => $session->id,
                'user_id' => auth()->id(),
            ]);
            abort(403);
        }

        if ($session->status !== ExtractionStatus::NeedsClarification) {
            Log::warning('[Controller][SessionController][clarify] Esclarecimento ignorado: sessão não aguarda respostas.', [
                'session_id' => $session->id,
                'status' => $session->status->value,
            ]);
            abort(403);
        }

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

        Log::info('[Controller][SessionController][clarify] Respostas registradas e job redespachado. Fim da execusão.', [
            'session_id' => $session->id,
            'respostas_recebidas' => count($answered),
            'round' => ($clarifications['round'] ?? 0) + 1,
        ]);

        return redirect()->route('sessions.show', $session);
    }

    public function updateFoodShared(UpdateFoodSharedRequest $request, Session $session): RedirectResponse
    {
        if ($session->user_id !== auth()->id()) {
            abort(403);
        }

        if ($session->analysis_status === AnalysisStatus::Processing) {
            abort(403);
        }

        $session->update(['food_shared' => $request->validated('food_shared')]);

        return back();
    }

    public function analyze(Session $session): RedirectResponse
    {
        Log::info('[Controller][SessionController][analyze] Inicio da execusão.', [
            'session_id' => $session->id,
            'analysis_status' => $session->analysis_status->value,
        ]);

        if ($session->user_id !== auth()->id()) {
            Log::warning('[Controller][SessionController][analyze] Acesso negado: usuário não é dono da sessão.', [
                'session_id' => $session->id,
                'user_id' => auth()->id(),
            ]);
            abort(403);
        }

        if ($session->status !== ExtractionStatus::Completed) {
            Log::warning('[Controller][SessionController][analyze] Análise bloqueada: extração do recibo ainda não concluída.', [
                'session_id' => $session->id,
                'status' => $session->status->value,
            ]);
            abort(403);
        }

        if ($session->participants()->count() < 1) {
            Log::warning('[Controller][SessionController][analyze] Análise bloqueada: nenhum participante enviou dados.', [
                'session_id' => $session->id,
            ]);
            abort(403);
        }

        $blocked = [AnalysisStatus::Processing, AnalysisStatus::NeedsClarification];
        if (in_array($session->analysis_status, $blocked, true)) {
            Log::warning('[Controller][SessionController][analyze] Análise bloqueada: status atual não permite reprocessar.', [
                'session_id' => $session->id,
                'analysis_status' => $session->analysis_status->value,
            ]);
            abort(403);
        }

        $session->update([
            'analysis_status' => AnalysisStatus::Processing,
            'analysis_result' => null,
            'analysis_clarifications' => null,
            'analysis_failure_reason' => null,
        ]);

        event(new ReceiptAnalysisUpdated($session->id, AnalysisStatus::Processing->value));

        AnalyzeBill::dispatch($session);

        Log::info('[Controller][SessionController][analyze] Job de análise despachado. Fim da execusão.', [
            'session_id' => $session->id,
        ]);

        return redirect()->route('sessions.show', $session);
    }

    /**
     * Rescue an analysis orphaned in "processing" when the queue dies without ever
     * running the job's failed(). Mirror of markExtractionTimedOut for the split
     * step. Idempotent: only acts while still processing.
     */
    public function markAnalysisTimedOut(Session $session): RedirectResponse
    {
        if ($session->user_id !== auth()->id()) {
            Log::warning('[Controller][SessionController][markAnalysisTimedOut] Acesso negado: usuário não é dono da sessão.', [
                'session_id' => $session->id,
                'user_id' => auth()->id(),
            ]);
            abort(403);
        }

        if ($session->analysis_status === AnalysisStatus::Processing) {
            $reason = 'A análise demorou demais e não pôde ser concluída. Tente novamente.';

            $session->update([
                'analysis_status' => AnalysisStatus::Failed,
                'analysis_failure_reason' => $reason,
            ]);

            event(new ReceiptAnalysisUpdated($session->id, AnalysisStatus::Failed->value, $reason));

            Log::warning('[Controller][SessionController][markAnalysisTimedOut] Análise presa em processamento marcada como falha.', [
                'session_id' => $session->id,
            ]);
        }

        return redirect()->route('sessions.show', $session);
    }

    public function clarifyAnalysis(ClarifyAnalysisRequest $request, Session $session): RedirectResponse
    {
        Log::info('[Controller][SessionController][clarifyAnalysis] Inicio da execusão.', [
            'session_id' => $session->id,
            'analysis_status' => $session->analysis_status->value,
        ]);

        if ($session->user_id !== auth()->id()) {
            Log::warning('[Controller][SessionController][clarifyAnalysis] Acesso negado: usuário não é dono da sessão.', [
                'session_id' => $session->id,
                'user_id' => auth()->id(),
            ]);
            abort(403);
        }

        if ($session->analysis_status !== AnalysisStatus::NeedsClarification) {
            Log::warning('[Controller][SessionController][clarifyAnalysis] Esclarecimento ignorado: análise não aguarda respostas.', [
                'session_id' => $session->id,
                'analysis_status' => $session->analysis_status->value,
            ]);
            abort(403);
        }

        $clarifications = $session->analysis_clarifications ?? [];
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
            'analysis_status' => AnalysisStatus::Processing,
            'analysis_clarifications' => [
                'round' => ($clarifications['round'] ?? 0) + 1,
                'answered' => $answered,
                'pending' => [],
            ],
        ]);

        event(new ReceiptAnalysisUpdated($session->id, AnalysisStatus::Processing->value));

        AnalyzeBill::dispatch($session);

        Log::info('[Controller][SessionController][clarifyAnalysis] Respostas registradas e job redespachado. Fim da execusão.', [
            'session_id' => $session->id,
            'respostas_recebidas' => count($answered),
            'round' => ($clarifications['round'] ?? 0) + 1,
        ]);

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
                'food_shared' => $session->food_shared,
                'analysis_status' => $session->analysis_status->value,
                'analysis_clarifications' => $session->analysis_clarifications,
                'analysis_result' => $session->analysis_result,
                'analysis_failure_reason' => $session->analysis_failure_reason,
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
