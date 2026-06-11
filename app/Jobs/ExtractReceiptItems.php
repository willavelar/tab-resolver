<?php

namespace App\Jobs;

use App\Enums\ExtractionStatus;
use App\Events\ReceiptExtractionUpdated;
use App\Models\Session;
use App\Services\Receipt\ExtractionResult;
use App\Services\Receipt\ReceiptExtractor;
use App\Services\Receipt\ReceiptReconciliation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExtractReceiptItems implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Hard cap on clarification rounds before forcing a final result. */
    public const MAX_ROUNDS = 2;

    public int $tries = 3;

    /**
     * Vision API calls can take a while; keep this above the worker default (60s)
     * so a slow extraction is not SIGKILL'd (which would leave the session stuck
     * in "processing" because failed() never runs on a kill).
     */
    public int $timeout = 120;

    /**
     * Treat a timeout as a terminal failure: run failed() (which broadcasts the
     * error and unsticks the session) instead of silently re-queueing.
     */
    public bool $failOnTimeout = true;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function __construct(public Session $session) {}

    public function handle(ReceiptExtractor $extractor): void
    {
        Log::info('[Job][ExtractReceiptItems][handle] Inicio da execusão.', [
            'session_id' => $this->session->id,
        ]);

        $clarifications = $this->session->clarifications ?? [];
        $answered = $clarifications['answered'] ?? [];
        $round = $clarifications['round'] ?? 0;
        $forceFinal = $round >= self::MAX_ROUNDS;

        $absolutePath = Storage::disk('public')->path($this->session->image_path);

        Log::info('[Job][ExtractReceiptItems][handle] Iniciando extração da imagem.', [
            'session_id' => $this->session->id,
            'round' => $round,
            'force_final' => $forceFinal,
            'respostas_anteriores' => count($answered),
        ]);

        $result = $extractor->extract($absolutePath, $answered, $forceFinal);

        if ($result->needsInput() && ! $forceFinal) {
            $this->requestClarification($round, $answered, $result->questions, $result->raw);

            Log::info('[Job][ExtractReceiptItems][handle] Extração precisa de esclarecimento. Fim da execusão.', [
                'session_id' => $this->session->id,
                'perguntas' => count($result->questions),
                'round' => $round,
            ]);

            return;
        }

        // A IA leu a conta, mas pode ter lido valores errados: confere se a conta
        // fecha (linhas, soma vs. subtotal, subtotal + gorjeta vs. total). Na rodada
        // final não bloqueia — respeita o cap de rodadas e conclui mesmo assim.
        if (! $forceFinal) {
            $reconQuestions = ReceiptReconciliation::check(
                $result->items,
                $result->subtotal,
                $result->serviceCharge,
                $result->total,
            );

            if ($reconQuestions !== []) {
                $this->requestClarification($round, $answered, $reconQuestions, $result->raw);

                Log::info('[Job][ExtractReceiptItems][handle] Conta não fechou na reconciliação. Fim da execusão.', [
                    'session_id' => $this->session->id,
                    'divergencias' => count($reconQuestions),
                    'round' => $round,
                ]);

                return;
            }
        }

        $this->session->items()->delete();

        foreach ($result->items as $index => $item) {
            $this->session->items()->create([
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['total_price'],
                'category' => $item['category'],
                'position' => $index + 1,
            ]);
        }

        $this->session->forceFill([
            'status' => ExtractionStatus::Completed,
            'subtotal' => $result->subtotal,
            'service_charge' => $result->serviceCharge,
            'service_charge_percentage' => $result->serviceChargePercentage ?? $this->derivePercentage($result),
            'total' => $result->total,
            'raw_extraction' => $result->raw,
            'clarifications' => null,
            'processed_at' => now(),
            'failure_reason' => null,
        ])->save();

        event(new ReceiptExtractionUpdated(
            $this->session->id,
            ExtractionStatus::Completed->value,
        ));

        Log::info('[Job][ExtractReceiptItems][handle] Extração concluída. Fim da execusão.', [
            'session_id' => $this->session->id,
            'itens' => count($result->items),
            'subtotal' => $result->subtotal,
            'total' => $result->total,
        ]);
    }

    /**
     * Estaciona a sessão aguardando esclarecimento do dono, reaproveitado tanto
     * pelas perguntas da IA quanto pelas divergências da reconciliação.
     *
     * @param  array<int, array{question: string, answer: string}>  $answered
     * @param  array<int, array{id: string, prompt: string, type: string, options: array<int, string>}>  $questions
     * @param  array<string, mixed>  $raw
     */
    private function requestClarification(int $round, array $answered, array $questions, array $raw): void
    {
        $this->session->forceFill([
            'status' => ExtractionStatus::NeedsClarification,
            'clarifications' => [
                'round' => $round,
                'answered' => $answered,
                'pending' => $questions,
            ],
            'raw_extraction' => $raw,
            'failure_reason' => null,
        ])->save();

        event(new ReceiptExtractionUpdated(
            $this->session->id,
            ExtractionStatus::NeedsClarification->value,
        ));
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('[Job][ExtractReceiptItems][failed] Inicio da execusão: job de extração falhou.', [
            'session_id' => $this->session->id,
            'erro' => $exception->getMessage(),
        ]);

        $this->session->forceFill([
            'status' => ExtractionStatus::Failed,
            'failure_reason' => $exception->getMessage(),
        ])->save();

        event(new ReceiptExtractionUpdated(
            $this->session->id,
            ExtractionStatus::Failed->value,
            $exception->getMessage(),
        ));

        Log::info('[Job][ExtractReceiptItems][failed] Estado de falha persistido. Fim da execusão.', [
            'session_id' => $this->session->id,
        ]);
    }

    /**
     * Derive the tip percentage when only the absolute charge is known.
     */
    private function derivePercentage(ExtractionResult $result): ?float
    {
        if ($result->subtotal > 0 && $result->serviceCharge > 0) {
            return round($result->serviceCharge / $result->subtotal * 100, 2);
        }

        return null;
    }
}
