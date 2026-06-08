<?php

namespace App\Services\Receipt;

class FakeReceiptExtractor implements ReceiptExtractor
{
    public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult
    {
        $items = [
            ['name' => 'Cerveja Heineken', 'quantity' => 2.0, 'unit_price' => 15.0, 'total_price' => 30.0, 'category' => 'drink'],
            ['name' => 'Porção de batata', 'quantity' => 1.0, 'unit_price' => 20.0, 'total_price' => 20.0, 'category' => 'food'],
        ];

        return ExtractionResult::complete(
            items: $items,
            subtotal: 50.0,
            serviceCharge: 5.0,
            serviceChargePercentage: 10.0,
            total: 55.0,
            raw: ['status' => 'complete', 'items' => $items, 'subtotal' => 50.0, 'service_charge' => 5.0, 'total' => 55.0],
        );
    }
}
