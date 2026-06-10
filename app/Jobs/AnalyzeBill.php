<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Events\BillAnalysisCompleted;
use App\Events\ReceiptAnalysisUpdated;
use App\Models\Session;
use App\Services\Bill\BillSplitter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeBill implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Hard cap on clarification rounds before forcing a final result. */
    public const MAX_ROUNDS = 2;

    public int $tries = 3;

    public int $timeout = 180;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function __construct(public Session $session) {}

    public function handle(BillSplitter $splitter): void
    {
        Log::info('[Job][AnalyzeBill][handle] Inicio da execusão.', [
            'session_id' => $this->session->id,
        ]);

        $this->session->loadMissing('participants', 'items');

        $clarifications = $this->session->analysis_clarifications ?? [];
        $answered = $clarifications['answered'] ?? [];
        $round = $clarifications['round'] ?? 0;
        $forceFinal = $round >= self::MAX_ROUNDS;

        $participants = $this->session->participants
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
            ->all();

        $result = $splitter->split(
            $this->session,
            $participants,
            (bool) $this->session->food_shared,
            $answered,
            $forceFinal,
        );

        if ($result->needsInput() && ! $forceFinal) {
            $this->session->forceFill([
                'analysis_status' => AnalysisStatus::NeedsClarification,
                'analysis_clarifications' => [
                    'round' => $round,
                    'answered' => $answered,
                    'pending' => $result->questions,
                ],
                'analysis_failure_reason' => null,
            ])->save();

            event(new ReceiptAnalysisUpdated($this->session->id, AnalysisStatus::NeedsClarification->value));

            Log::info('[Job][AnalyzeBill][handle] Análise precisa de esclarecimento. Fim da execusão.', [
                'session_id' => $this->session->id,
                'perguntas' => count($result->questions),
                'round' => $round,
            ]);

            return;
        }

        $byId = collect($result->allocations)->keyBy('participant_id');
        foreach ($this->session->participants as $participant) {
            $alloc = $byId->get($participant->id);
            if ($alloc === null) {
                continue;
            }
            $participant->forceFill([
                'amount_due' => $alloc['total'],
                'breakdown' => $alloc,
            ])->save();
        }

        $this->session->forceFill([
            'analysis_status' => AnalysisStatus::Completed,
            'analysis_result' => ['participants' => $result->allocations],
            'analysis_clarifications' => null,
            'analysis_failure_reason' => null,
            'analyzed_at' => now(),
        ])->save();

        event(new ReceiptAnalysisUpdated($this->session->id, AnalysisStatus::Completed->value));
        event(new BillAnalysisCompleted($this->session->id));

        Log::info('[Job][AnalyzeBill][handle] Análise concluída. Fim da execusão.', [
            'session_id' => $this->session->id,
            'participantes' => count($result->allocations),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('[Job][AnalyzeBill][failed] Inicio da execusão: job de análise falhou.', [
            'session_id' => $this->session->id,
            'erro' => $exception->getMessage(),
        ]);

        $this->session->forceFill([
            'analysis_status' => AnalysisStatus::Failed,
            'analysis_failure_reason' => $exception->getMessage(),
        ])->save();

        event(new ReceiptAnalysisUpdated(
            $this->session->id,
            AnalysisStatus::Failed->value,
            $exception->getMessage(),
        ));
    }
}
