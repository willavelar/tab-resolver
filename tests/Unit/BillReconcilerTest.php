<?php

use App\Services\Bill\BillReconciler;

function receiptFixture(): array
{
    return [
        ['name' => 'Parmegiana', 'quantity' => 1.0, 'unit_price' => 119.90, 'total_price' => 119.90, 'category' => 'food'],
        ['name' => 'Bife a Cavalo', 'quantity' => 3.0, 'unit_price' => 50.00, 'total_price' => 150.00, 'category' => 'food'],
        ['name' => 'Heineken', 'quantity' => 3.0, 'unit_price' => 9.90, 'total_price' => 29.70, 'category' => 'drink'],
        ['name' => 'Moscow Mule', 'quantity' => 2.0, 'unit_price' => 31.90, 'total_price' => 63.80, 'category' => 'drink'],
    ];
}

function participantsFixture(): array
{
    return [
        ['id' => 'w', 'name' => 'William'],
        ['id' => 'c', 'name' => 'Camila'],
    ];
}

it('reconciles the worked example with shared food', function () {
    $claims = [
        ['participant_id' => 'w', 'items' => [
            ['name' => 'Moscow Mule', 'quantity' => 1.0],
            ['name' => 'Heineken', 'quantity' => 2.0],
        ]],
        ['participant_id' => 'c', 'items' => [
            ['name' => 'Heineken', 'quantity' => 1.0],
            ['name' => 'Moscow Mule', 'quantity' => 1.0],
        ]],
    ];

    $result = (new BillReconciler)->reconcile(
        items: receiptFixture(),
        participants: participantsFixture(),
        claims: $claims,
        foodShared: true,
        serviceChargePercentage: 10.0,
        total: 399.74,
        forceFinal: false,
    );

    expect($result->needsInput())->toBeFalse();

    $byId = collect($result->allocations)->keyBy('participant_id');

    expect($byId['w']['shared_food_share'])->toBe(134.95)
        ->and($byId['c']['shared_food_share'])->toBe(134.95);

    expect($byId['w']['subtotal'])->toBe(186.65);
    expect($byId['c']['subtotal'])->toBe(176.75);

    $grand = collect($result->allocations)->sum('total');
    expect(abs($grand - 399.74))->toBeLessThanOrEqual(0.01);
});

it('asks a question when a drink is left unclaimed', function () {
    $claims = [
        ['participant_id' => 'w', 'items' => [['name' => 'Moscow Mule', 'quantity' => 1.0]]],
        ['participant_id' => 'c', 'items' => [['name' => 'Moscow Mule', 'quantity' => 1.0]]],
    ];

    $result = (new BillReconciler)->reconcile(
        items: receiptFixture(),
        participants: participantsFixture(),
        claims: $claims,
        foodShared: true,
        serviceChargePercentage: 10.0,
        total: 399.74,
        forceFinal: false,
    );

    expect($result->needsInput())->toBeTrue()
        ->and($result->questions)->not->toBeEmpty()
        ->and($result->questions[0]['prompt'])->toContain('Heineken');
});

it('asks a question when food is unclaimed and food is not shared', function () {
    $claims = [
        ['participant_id' => 'w', 'items' => [
            ['name' => 'Moscow Mule', 'quantity' => 1.0], ['name' => 'Heineken', 'quantity' => 2.0],
        ]],
        ['participant_id' => 'c', 'items' => [
            ['name' => 'Moscow Mule', 'quantity' => 1.0], ['name' => 'Heineken', 'quantity' => 1.0],
        ]],
    ];

    $result = (new BillReconciler)->reconcile(
        items: receiptFixture(),
        participants: participantsFixture(),
        claims: $claims,
        foodShared: false,
        serviceChargePercentage: 10.0,
        total: 399.74,
        forceFinal: false,
    );

    expect($result->needsInput())->toBeTrue()
        ->and($result->questions[0]['prompt'])->toContain('Parmegiana');
});

it('asks a question when a participant over-claims beyond receipt quantity', function () {
    $claims = [
        ['participant_id' => 'w', 'items' => [['name' => 'Moscow Mule', 'quantity' => 5.0]]],
        ['participant_id' => 'c', 'items' => []],
    ];

    $result = (new BillReconciler)->reconcile(
        items: receiptFixture(),
        participants: participantsFixture(),
        claims: $claims,
        foodShared: true,
        serviceChargePercentage: 10.0,
        total: 399.74,
        forceFinal: false,
    );

    expect($result->needsInput())->toBeTrue();
});

it('always closes on the final forced round by sharing all leftovers', function () {
    $claims = [
        ['participant_id' => 'w', 'items' => [['name' => 'Moscow Mule', 'quantity' => 1.0]]],
        ['participant_id' => 'c', 'items' => [['name' => 'Moscow Mule', 'quantity' => 1.0]]],
    ];

    $result = (new BillReconciler)->reconcile(
        items: receiptFixture(),
        participants: participantsFixture(),
        claims: $claims,
        foodShared: true,
        serviceChargePercentage: 10.0,
        total: 399.74,
        forceFinal: true,
    );

    expect($result->needsInput())->toBeFalse();
    $grand = collect($result->allocations)->sum('total');
    expect(abs($grand - 399.74))->toBeLessThanOrEqual(0.02);
});

it('aggregates duplicate receipt lines with the same name', function () {
    $items = [
        ['name' => 'Heineken', 'quantity' => 2.0, 'unit_price' => 9.90, 'total_price' => 19.80, 'category' => 'drink'],
        ['name' => 'Heineken', 'quantity' => 1.0, 'unit_price' => 9.90, 'total_price' => 9.90, 'category' => 'drink'],
    ];
    $participants = [['id' => 'a', 'name' => 'Ana'], ['id' => 'b', 'name' => 'Bia']];
    $claims = [
        ['participant_id' => 'a', 'items' => [['name' => 'Heineken', 'quantity' => 2.0]]],
        ['participant_id' => 'b', 'items' => [['name' => 'Heineken', 'quantity' => 1.0]]],
    ];

    $result = (new BillReconciler)->reconcile(
        items: $items,
        participants: $participants,
        claims: $claims,
        foodShared: true,
        serviceChargePercentage: 0.0,
        total: 29.70,
        forceFinal: false,
    );

    expect($result->needsInput())->toBeFalse();
    $grand = collect($result->allocations)->sum('total');
    expect(abs($grand - 29.70))->toBeLessThanOrEqual(0.01);
});

it('asks for participants when none submitted', function () {
    $result = (new BillReconciler)->reconcile(
        items: receiptFixture(),
        participants: [],
        claims: [],
        foodShared: true,
        serviceChargePercentage: 10.0,
        total: 399.74,
        forceFinal: true,
    );

    expect($result->needsInput())->toBeTrue();
});
