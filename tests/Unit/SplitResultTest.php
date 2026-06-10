<?php

use App\Services\Bill\SplitResult;

it('builds a complete result', function () {
    $allocations = [
        ['participant_id' => 'p1', 'name' => 'William', 'items' => [], 'shared_food_share' => 0.0, 'subtotal' => 10.0, 'tip' => 1.0, 'total' => 11.0],
    ];

    $result = SplitResult::complete($allocations, ['ok' => true]);

    expect($result->needsInput())->toBeFalse()
        ->and($result->allocations)->toBe($allocations);
});

it('builds a needs-input result', function () {
    $questions = [['id' => 'q1', 'prompt' => 'Quem bebeu a Heineken?', 'type' => 'text', 'options' => []]];

    $result = SplitResult::requestInput($questions, ['raw' => 1]);

    expect($result->needsInput())->toBeTrue()
        ->and($result->questions)->toBe($questions)
        ->and($result->allocations)->toBe([]);
});
