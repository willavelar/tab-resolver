<?php

namespace App\Jobs;

use App\Enums\ExtractionStatus;
use App\Events\ReceiptExtractionUpdated;
use App\Models\Session;
use App\Services\Receipt\ReceiptExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExtractReceiptItems implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Vision API calls can take a while; keep this above the worker default (60s)
     * so a slow extraction is not SIGKILL'd (which would leave the session stuck
     * in "processing" because failed() never runs on a kill).
     */
    public int $timeout = 120;

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
        $absolutePath = Storage::disk('public')->path($this->session->image_path);

        $result = $extractor->extract($absolutePath);

        $this->session->items()->delete();

        foreach ($result->items as $index => $item) {
            $this->session->items()->create([
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['total_price'],
                'position' => $index + 1,
            ]);
        }

        $this->session->forceFill([
            'status' => ExtractionStatus::Completed,
            'subtotal' => $result->subtotal,
            'service_charge' => $result->serviceCharge,
            'total' => $result->total,
            'raw_extraction' => $result->raw,
            'processed_at' => now(),
            'failure_reason' => null,
        ])->save();

        event(new ReceiptExtractionUpdated(
            $this->session->id,
            ExtractionStatus::Completed->value,
        ));
    }

    public function failed(Throwable $exception): void
    {
        $this->session->forceFill([
            'status' => ExtractionStatus::Failed,
            'failure_reason' => $exception->getMessage(),
        ])->save();

        event(new ReceiptExtractionUpdated(
            $this->session->id,
            ExtractionStatus::Failed->value,
            $exception->getMessage(),
        ));
    }
}
