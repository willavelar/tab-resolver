<?php

use App\Services\Receipt\ExtractionResult;

test('complete result carries items and totals and is not needs-input', function () {
    $result = ExtractionResult::complete(
        items: [['name' => 'X', 'quantity' => 1.0, 'unit_price' => 5.0, 'total_price' => 5.0, 'category' => 'food']],
        subtotal: 5.0,
        serviceCharge: 0.5,
        serviceChargePercentage: 10.0,
        total: 5.5,
        raw: ['status' => 'complete'],
    );

    expect($result->status)->toBe('complete')
        ->and($result->needsInput())->toBeFalse()
        ->and($result->items)->toHaveCount(1)
        ->and($result->serviceChargePercentage)->toBe(10.0);
});

test('requestInput result carries questions and is needs-input', function () {
    $result = ExtractionResult::requestInput(
        questions: [['id' => 'q1', 'prompt' => 'Comida ou Bebida?', 'type' => 'choice', 'options' => ['Comida', 'Bebida']]],
        raw: ['status' => 'needs_input'],
    );

    expect($result->status)->toBe('needs_input')
        ->and($result->needsInput())->toBeTrue()
        ->and($result->questions)->toHaveCount(1)
        ->and($result->items)->toBe([]);
});
