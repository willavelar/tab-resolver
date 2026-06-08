<?php

use App\Enums\ExtractionStatus;
use App\Enums\ItemCategory;
use App\Events\ReceiptExtractionUpdated;
use App\Jobs\ExtractReceiptItems;
use App\Models\Session;
use App\Models\SessionItem;
use App\Models\User;
use App\Services\Receipt\ExtractionResult;
use App\Services\Receipt\FakeReceiptExtractor;
use App\Services\Receipt\ReceiptExtractor;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

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

    expect($result->status)->toBe('complete')
        ->and($result->items)->toHaveCount(2)
        ->and($result->items[0]['name'])->toBe('Cerveja Heineken')
        ->and($result->items[0]['category'])->toBe('drink')
        ->and($result->items[1]['category'])->toBe('food')
        ->and($result->subtotal)->toBe(50.0)
        ->and($result->serviceCharge)->toBe(5.0)
        ->and($result->serviceChargePercentage)->toBe(10.0)
        ->and($result->total)->toBe(55.0)
        ->and($result->raw)->toBeArray();
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
        ->and($session->raw_extraction)->toBeArray()
        ->and((float) $session->service_charge_percentage)->toBe(10.0)
        ->and($session->items()->where('category', 'drink')->count())->toBe(1)
        ->and($session->items()->where('category', 'food')->count())->toBe(1);

    Event::assertDispatched(ReceiptExtractionUpdated::class, function ($e) use ($session) {
        return $e->sessionId === $session->id && $e->status === ExtractionStatus::Completed->value;
    });
});

test('the job marks the session failed when extraction throws', function () {
    Event::fake();
    $this->app->instance(ReceiptExtractor::class, new class implements ReceiptExtractor
    {
        public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult
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

test('the owner can trigger extraction and the job is queued', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $session = Session::factory()->for($owner)->create(['status' => ExtractionStatus::Pending]);

    $this->actingAs($owner)
        ->post("/sessions/{$session->id}/extract")
        ->assertRedirect("/sessions/{$session->id}");

    expect($session->fresh()->status)->toBe(ExtractionStatus::Processing);
    Queue::assertPushed(ExtractReceiptItems::class);
});

test('a non-owner cannot trigger extraction', function () {
    Queue::fake();
    $session = Session::factory()->for(User::factory())->create(['status' => ExtractionStatus::Pending]);

    $this->actingAs(User::factory()->create())
        ->post("/sessions/{$session->id}/extract")
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('extraction cannot be retriggered while processing or completed', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $session = Session::factory()->for($owner)->create(['status' => ExtractionStatus::Processing]);

    $this->actingAs($owner)
        ->post("/sessions/{$session->id}/extract")
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('a failed extraction can be retried', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $session = Session::factory()->for($owner)->create(['status' => ExtractionStatus::Failed]);

    $this->actingAs($owner)
        ->post("/sessions/{$session->id}/extract")
        ->assertRedirect("/sessions/{$session->id}");

    Queue::assertPushed(ExtractReceiptItems::class);
});

test('item category enum exposes pt-br labels', function () {
    expect(ItemCategory::Food->value)->toBe('food')
        ->and(ItemCategory::Drink->value)->toBe('drink')
        ->and(ItemCategory::Food->label())->toBe('Comida')
        ->and(ItemCategory::Drink->label())->toBe('Bebida');
});

test('extraction status has a needs_clarification case', function () {
    expect(ExtractionStatus::NeedsClarification->value)->toBe('needs_clarification');
});

test('session item casts category and session casts new extraction fields', function () {
    $session = Session::factory()->for(User::factory())->create([
        'service_charge_percentage' => 10,
        'clarifications' => ['round' => 1, 'answered' => [], 'pending' => []],
    ]);

    $item = SessionItem::create([
        'bill_session_id' => $session->id,
        'name' => 'Heineken',
        'quantity' => 2,
        'unit_price' => 9.90,
        'total_price' => 19.80,
        'category' => ItemCategory::Drink,
        'position' => 1,
    ]);

    $session->refresh();

    expect($item->fresh()->category)->toBe(ItemCategory::Drink)
        ->and((float) $session->service_charge_percentage)->toBe(10.0)
        ->and($session->clarifications)->toBeArray()
        ->and($session->clarifications['round'])->toBe(1);
});

test('the job parks the session for clarification when the model asks', function () {
    Event::fake();
    $this->app->instance(ReceiptExtractor::class, new class implements ReceiptExtractor
    {
        public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult
        {
            return ExtractionResult::requestInput(
                questions: [['id' => 'q1', 'prompt' => 'Caipirinha é Comida ou Bebida?', 'type' => 'choice', 'options' => ['Comida', 'Bebida']]],
                raw: ['status' => 'needs_input'],
            );
        }
    });

    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Processing,
        'image_path' => 'receipts/example.jpg',
    ]);

    ExtractReceiptItems::dispatchSync($session);
    $session->refresh();

    expect($session->status)->toBe(ExtractionStatus::NeedsClarification)
        ->and($session->items()->count())->toBe(0)
        ->and($session->clarifications['pending'])->toHaveCount(1)
        ->and($session->clarifications['pending'][0]['id'])->toBe('q1');

    Event::assertDispatched(ReceiptExtractionUpdated::class, function ($e) use ($session) {
        return $e->sessionId === $session->id && $e->status === ExtractionStatus::NeedsClarification->value;
    });
});

test('the job forces a final result once the round cap is reached', function () {
    Event::fake();
    $this->app->instance(ReceiptExtractor::class, new class implements ReceiptExtractor
    {
        public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult
        {
            // Would keep asking, but on the final round the job ignores questions.
            return ExtractionResult::requestInput(questions: [['id' => 'q1', 'prompt' => 'x', 'type' => 'text', 'options' => []]], raw: []);
        }
    });

    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Processing,
        'image_path' => 'receipts/example.jpg',
        'clarifications' => ['round' => 2, 'answered' => [], 'pending' => []],
    ]);

    ExtractReceiptItems::dispatchSync($session);
    $session->refresh();

    expect($session->status)->toBe(ExtractionStatus::Completed);
});

test('the owner can answer clarification questions and re-dispatch', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $session = Session::factory()->for($owner)->create([
        'status' => ExtractionStatus::NeedsClarification,
        'clarifications' => [
            'round' => 0,
            'answered' => [],
            'pending' => [['id' => 'q1', 'prompt' => 'Caipirinha é Comida ou Bebida?', 'type' => 'choice', 'options' => ['Comida', 'Bebida']]],
        ],
    ]);

    $this->actingAs($owner)
        ->post("/sessions/{$session->id}/clarify", ['answers' => ['q1' => 'Bebida']])
        ->assertRedirect("/sessions/{$session->id}");

    $session->refresh();

    expect($session->status)->toBe(ExtractionStatus::Processing)
        ->and($session->clarifications['round'])->toBe(1)
        ->and($session->clarifications['answered'])->toHaveCount(1)
        ->and($session->clarifications['answered'][0]['question'])->toBe('Caipirinha é Comida ou Bebida?')
        ->and($session->clarifications['answered'][0]['answer'])->toBe('Bebida');

    Queue::assertPushed(ExtractReceiptItems::class);
});

test('clarify is rejected unless the session is awaiting clarification', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $session = Session::factory()->for($owner)->create(['status' => ExtractionStatus::Completed]);

    $this->actingAs($owner)
        ->post("/sessions/{$session->id}/clarify", ['answers' => ['q1' => 'Bebida']])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('a non-owner cannot answer clarification questions', function () {
    Queue::fake();
    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::NeedsClarification,
        'clarifications' => ['round' => 0, 'answered' => [], 'pending' => [['id' => 'q1', 'prompt' => 'x', 'type' => 'text', 'options' => []]]],
    ]);

    $this->actingAs(User::factory()->create())
        ->post("/sessions/{$session->id}/clarify", ['answers' => ['q1' => 'y']])
        ->assertForbidden();

    Queue::assertNothingPushed();
});
