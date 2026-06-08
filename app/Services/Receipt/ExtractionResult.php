<?php

namespace App\Services\Receipt;

class ExtractionResult
{
    /**
     * @param  array<int, array{name: string, quantity: float, unit_price: float, total_price: float}>  $items
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly array $items,
        public readonly float $subtotal,
        public readonly float $serviceCharge,
        public readonly float $total,
        public readonly array $raw,
    ) {}
}
