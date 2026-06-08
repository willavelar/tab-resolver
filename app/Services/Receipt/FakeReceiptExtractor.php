<?php

namespace App\Services\Receipt;

class FakeReceiptExtractor implements ReceiptExtractor
{
    public function extract(string $absoluteImagePath): ExtractionResult
    {
        $items = [
            ['name' => 'Cerveja Heineken', 'quantity' => 2.0, 'unit_price' => 15.0, 'total_price' => 30.0],
            ['name' => 'Porção de batata', 'quantity' => 1.0, 'unit_price' => 20.0, 'total_price' => 20.0],
        ];

        return new ExtractionResult(
            items: $items,
            subtotal: 50.0,
            serviceCharge: 5.0,
            total: 55.0,
            raw: ['items' => $items, 'subtotal' => 50.0, 'service_charge' => 5.0, 'total' => 55.0],
        );
    }
}
