<?php

use App\Models\Session;
use App\Models\User;
use App\Services\Bill\BillSplitter;
use App\Services\Bill\FakeBillSplitter;

it('is the bound implementation in tests', function () {
    expect(app(BillSplitter::class))->toBeInstanceOf(FakeBillSplitter::class);
});

it('returns complete allocations for the canned example', function () {
    $session = Session::factory()->for(User::factory())->create([
        'service_charge_percentage' => 10.0,
        'total' => 399.74,
    ]);

    $items = [
        ['Parmegiana', 1, 119.90, 119.90, 'food'],
        ['Bife a Cavalo', 3, 50.00, 150.00, 'food'],
        ['Heineken', 3, 9.90, 29.70, 'drink'],
        ['Moscow Mule', 2, 31.90, 63.80, 'drink'],
    ];
    foreach ($items as $i => [$name, $qty, $unit, $line, $cat]) {
        $session->items()->create([
            'name' => $name, 'quantity' => $qty, 'unit_price' => $unit,
            'total_price' => $line, 'category' => $cat, 'position' => $i + 1,
        ]);
    }
    $session->load('items');

    $participants = [
        ['id' => 'w', 'name' => 'William'],
        ['id' => 'c', 'name' => 'Camila'],
    ];

    $result = app(BillSplitter::class)->split($session, $participants, true, [], false);

    expect($result->needsInput())->toBeFalse()
        ->and($result->allocations)->toHaveCount(2);
});
