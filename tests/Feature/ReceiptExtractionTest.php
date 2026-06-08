<?php

use App\Enums\ExtractionStatus;
use App\Models\Session;
use App\Models\SessionItem;
use App\Models\User;
use App\Services\Receipt\FakeReceiptExtractor;
use App\Services\Receipt\ReceiptExtractor;

test('extraction status enum has the expected cases', function () {
    expect(ExtractionStatus::Pending->value)->toBe('pending');
    expect(ExtractionStatus::Processing->value)->toBe('processing');
    expect(ExtractionStatus::Completed->value)->toBe('completed');
    expect(ExtractionStatus::Failed->value)->toBe('failed');
});

test('a new session defaults to pending status', function () {
    $session = Session::factory()->for(User::factory())->create();

    expect($session->status)->toBe(ExtractionStatus::Pending);
});

test('a session has many ordered items with decimal casts', function () {
    $session = Session::factory()->for(User::factory())->create();

    SessionItem::create([
        'bill_session_id' => $session->id,
        'name' => 'Cerveja',
        'quantity' => 2,
        'unit_price' => 12.50,
        'total_price' => 25.00,
        'position' => 1,
    ]);

    $session->refresh();

    expect($session->items)->toHaveCount(1);
    expect($session->items->first()->name)->toBe('Cerveja');
    expect((float) $session->items->first()->total_price)->toBe(25.00);
});

test('the fake extractor returns a deterministic result', function () {
    $extractor = new FakeReceiptExtractor();

    expect($extractor)->toBeInstanceOf(ReceiptExtractor::class);

    $result = $extractor->extract('/tmp/whatever.jpg');

    expect($result->items)->toHaveCount(2);
    expect($result->items[0]['name'])->toBe('Cerveja Heineken');
    expect($result->subtotal)->toBe(50.0);
    expect($result->serviceCharge)->toBe(5.0);
    expect($result->total)->toBe(55.0);
    expect($result->raw)->toBeArray();
});
