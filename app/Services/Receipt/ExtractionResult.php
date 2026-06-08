<?php

namespace App\Services\Receipt;

class ExtractionResult
{
    /**
     * @param  array<int, array{name: string, quantity: float, unit_price: float, total_price: float, category: string}>  $items
     * @param  array<int, array{id: string, prompt: string, type: string, options: array<int, string>}>  $questions
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $status,
        public readonly array $items = [],
        public readonly float $subtotal = 0.0,
        public readonly float $serviceCharge = 0.0,
        public readonly ?float $serviceChargePercentage = null,
        public readonly float $total = 0.0,
        public readonly array $questions = [],
        public readonly array $raw = [],
    ) {}

    public function needsInput(): bool
    {
        return $this->status === 'needs_input';
    }

    /**
     * @param  array<int, array{name: string, quantity: float, unit_price: float, total_price: float, category: string}>  $items
     * @param  array<string, mixed>  $raw
     */
    public static function complete(
        array $items,
        float $subtotal,
        float $serviceCharge,
        ?float $serviceChargePercentage,
        float $total,
        array $raw,
    ): self {
        return new self(
            status: 'complete',
            items: $items,
            subtotal: $subtotal,
            serviceCharge: $serviceCharge,
            serviceChargePercentage: $serviceChargePercentage,
            total: $total,
            raw: $raw,
        );
    }

    /**
     * @param  array<int, array{id: string, prompt: string, type: string, options: array<int, string>}>  $questions
     * @param  array<string, mixed>  $raw
     */
    public static function requestInput(array $questions, array $raw): self
    {
        return new self(status: 'needs_input', questions: $questions, raw: $raw);
    }
}
