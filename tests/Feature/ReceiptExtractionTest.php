<?php

use App\Enums\ExtractionStatus;
use App\Events\ReceiptExtractionUpdated;
use App\Jobs\ExtractReceiptItems;
use App\Models\Session;
use App\Models\SessionItem;
use App\Models\User;
use App\Services\Receipt\ExtractionResult;
use App\Services\Receipt\FakeReceiptExtractor;
use App\Services\Receipt\ReceiptExtractor;
use Illuminate\Support\Facades\Event;

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
    $extractor = new FakeReceiptExtractor;

    expect($extractor)->toBeInstanceOf(ReceiptExtractor::class);

    $result = $extractor->extract('/tmp/whatever.jpg');

    expect($result->items)->toHaveCount(2);
    expect($result->items[0]['name'])->toBe('Cerveja Heineken');
    expect($result->subtotal)->toBe(50.0);
    expect($result->serviceCharge)->toBe(5.0);
    expect($result->total)->toBe(55.0);
    expect($result->raw)->toBeArray();
});

test('the job persists items and totals and marks the session completed', function () {
    Event::fake();
    $this->app->instance(ReceiptExtractor::class, new FakeReceiptExtractor);

    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Processing,
        'image_path' => 'receipts/example.jpg',
    ]);

    ExtractReceiptItems::dispatchSync($session);

    $session->refresh();

    expect($session->status)->toBe(ExtractionStatus::Completed)
        ->and($session->processed_at)->not->toBeNull()
        ->and((float) $session->subtotal)->toBe(50.0)
        ->and((float) $session->service_charge)->toBe(5.0)
        ->and((float) $session->total)->toBe(55.0)
        ->and($session->items()->count())->toBe(2)
        ->and($session->raw_extraction)->toBeArray();

    Event::assertDispatched(ReceiptExtractionUpdated::class, function ($e) use ($session) {
        return $e->sessionId === $session->id && $e->status === ExtractionStatus::Completed->value;
    });
});

test('the job marks the session failed when extraction throws', function () {
    Event::fake();
    $this->app->instance(ReceiptExtractor::class, new class implements ReceiptExtractor
    {
        public function extract(string $absoluteImagePath): ExtractionResult
        {
            throw new RuntimeException('boom');
        }
    });

    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Processing,
        'image_path' => 'receipts/example.jpg',
    ]);

    try {
        ExtractReceiptItems::dispatchSync($session);
    } catch (RuntimeException) {
        // dispatchSync rethrows after the job's failed() handler runs.
    }

    $session->refresh();

    expect($session->status)->toBe(ExtractionStatus::Failed)
        ->and($session->failure_reason)->toBe('boom');

    Event::assertDispatched(ReceiptExtractionUpdated::class, function ($e) {
        return $e->status === ExtractionStatus::Failed->value;
    });
});
