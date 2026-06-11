<?php

use App\Enums\AnalysisStatus;
use App\Enums\ExtractionStatus;
use App\Events\ReceiptAnalysisUpdated;
use App\Events\ReceiptExtractionUpdated;
use App\Jobs\AnalyzeBill;
use App\Jobs\ExtractReceiptItems;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\Event;

test('the extraction jobs fail on timeout instead of leaving the session stuck', function () {
    expect((new ExtractReceiptItems(new Session))->failOnTimeout)->toBeTrue()
        ->and((new AnalyzeBill(new Session))->failOnTimeout)->toBeTrue();
});

test('the owner can mark a stuck extraction as failed and a failure is broadcast', function () {
    Event::fake();
    $owner = User::factory()->create();
    $session = Session::factory()->for($owner)->create(['status' => ExtractionStatus::Processing]);

    $this->actingAs($owner)
        ->post("/sessions/{$session->id}/extract/timeout")
        ->assertRedirect("/sessions/{$session->id}");

    $session->refresh();

    expect($session->status)->toBe(ExtractionStatus::Failed)
        ->and($session->failure_reason)->not->toBeNull();

    Event::assertDispatched(ReceiptExtractionUpdated::class, function ($e) use ($session) {
        return $e->sessionId === $session->id
            && $e->status === ExtractionStatus::Failed->value
            && $e->failureReason !== null;
    });
});

test('marking extraction stuck is a no-op when it is no longer processing', function () {
    Event::fake();
    $owner = User::factory()->create();
    $session = Session::factory()->for($owner)->create(['status' => ExtractionStatus::Completed]);

    $this->actingAs($owner)
        ->post("/sessions/{$session->id}/extract/timeout")
        ->assertRedirect("/sessions/{$session->id}");

    expect($session->fresh()->status)->toBe(ExtractionStatus::Completed);

    Event::assertNotDispatched(ReceiptExtractionUpdated::class);
});

test('a non-owner cannot mark a stuck extraction as failed', function () {
    Event::fake();
    $session = Session::factory()->for(User::factory())->create(['status' => ExtractionStatus::Processing]);

    $this->actingAs(User::factory()->create())
        ->post("/sessions/{$session->id}/extract/timeout")
        ->assertForbidden();

    expect($session->fresh()->status)->toBe(ExtractionStatus::Processing);
    Event::assertNotDispatched(ReceiptExtractionUpdated::class);
});

test('the owner can mark a stuck analysis as failed and a failure is broadcast', function () {
    Event::fake();
    $owner = User::factory()->create();
    $session = Session::factory()->for($owner)->create([
        'status' => ExtractionStatus::Completed,
        'analysis_status' => AnalysisStatus::Processing,
    ]);

    $this->actingAs($owner)
        ->post("/sessions/{$session->id}/analyze/timeout")
        ->assertRedirect("/sessions/{$session->id}");

    $session->refresh();

    expect($session->analysis_status)->toBe(AnalysisStatus::Failed)
        ->and($session->analysis_failure_reason)->not->toBeNull();

    Event::assertDispatched(ReceiptAnalysisUpdated::class, function ($e) use ($session) {
        return $e->sessionId === $session->id
            && $e->status === AnalysisStatus::Failed->value
            && $e->failureReason !== null;
    });
});

test('marking analysis stuck is a no-op when it is no longer processing', function () {
    Event::fake();
    $owner = User::factory()->create();
    $session = Session::factory()->for($owner)->create([
        'status' => ExtractionStatus::Completed,
        'analysis_status' => AnalysisStatus::Completed,
    ]);

    $this->actingAs($owner)
        ->post("/sessions/{$session->id}/analyze/timeout")
        ->assertRedirect("/sessions/{$session->id}");

    expect($session->fresh()->analysis_status)->toBe(AnalysisStatus::Completed);

    Event::assertNotDispatched(ReceiptAnalysisUpdated::class);
});

test('a non-owner cannot mark a stuck analysis as failed', function () {
    Event::fake();
    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Completed,
        'analysis_status' => AnalysisStatus::Processing,
    ]);

    $this->actingAs(User::factory()->create())
        ->post("/sessions/{$session->id}/analyze/timeout")
        ->assertForbidden();

    expect($session->fresh()->analysis_status)->toBe(AnalysisStatus::Processing);
    Event::assertNotDispatched(ReceiptAnalysisUpdated::class);
});
