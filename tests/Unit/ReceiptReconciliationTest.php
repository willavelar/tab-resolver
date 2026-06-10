<?php

use App\Services\Receipt\ReceiptReconciliation;

function recItems(): array
{
    return [
        ['name' => 'Cerveja', 'quantity' => 2.0, 'unit_price' => 15.0, 'total_price' => 30.0, 'category' => 'drink'],
        ['name' => 'Batata', 'quantity' => 1.0, 'unit_price' => 20.0, 'total_price' => 20.0, 'category' => 'food'],
    ];
}

test('a balanced bill produces no clarification questions', function () {
    $questions = ReceiptReconciliation::check(recItems(), subtotal: 50.0, serviceCharge: 5.0, total: 55.0);

    expect($questions)->toBe([]);
});

test('cent-level rounding stays within tolerance', function () {
    $questions = ReceiptReconciliation::check(recItems(), subtotal: 50.03, serviceCharge: 5.0, total: 55.04);

    expect($questions)->toBe([]);
});

test('items not matching the subtotal raises a recon_subtotal question', function () {
    $questions = ReceiptReconciliation::check(recItems(), subtotal: 48.0, serviceCharge: 5.0, total: 53.0);

    $ids = array_column($questions, 'id');

    expect($ids)->toContain('recon_subtotal')
        ->and($questions[array_search('recon_subtotal', $ids)]['type'])->toBe('text')
        ->and($questions[array_search('recon_subtotal', $ids)]['options'])->toBe([])
        ->and($questions[array_search('recon_subtotal', $ids)]['prompt'])->toContain('48,00');
});

test('subtotal plus service not matching the total raises a recon_total question', function () {
    $questions = ReceiptReconciliation::check(recItems(), subtotal: 50.0, serviceCharge: 5.0, total: 60.0);

    expect(array_column($questions, 'id'))->toContain('recon_total');
});

test('a line whose quantity times unit price does not match its total raises a recon_line question', function () {
    $items = [
        ['name' => 'Cerveja', 'quantity' => 2.0, 'unit_price' => 15.0, 'total_price' => 40.0, 'category' => 'drink'],
    ];

    $questions = ReceiptReconciliation::check($items, subtotal: 40.0, serviceCharge: 0.0, total: 40.0);

    expect(array_column($questions, 'id'))->toContain('recon_line_0');
});
