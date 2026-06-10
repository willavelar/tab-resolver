<?php

use App\Enums\AnalysisStatus;
use App\Enums\ExtractionStatus;
use App\Jobs\AnalyzeBill;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('lets the owner toggle food_shared', function () {
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create(['food_shared' => true]);

    $this->actingAs($user)
        ->patch(route('sessions.food-shared', $session), ['food_shared' => false])
        ->assertRedirect();

    expect($session->fresh()->food_shared)->toBeFalse();
});

it('forbids a non-owner from toggling food_shared', function () {
    $session = Session::factory()->for(User::factory())->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('sessions.food-shared', $session), ['food_shared' => false])
        ->assertForbidden();
});

it('dispatches AnalyzeBill when receipt is completed and a participant exists', function () {
    Queue::fake();
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create(['status' => ExtractionStatus::Completed]);
    SessionParticipant::factory()->for($session, 'session')->create();

    $this->actingAs($user)
        ->post(route('sessions.analyze', $session))
        ->assertRedirect(route('sessions.show', $session));

    expect($session->fresh()->analysis_status)->toBe(AnalysisStatus::Processing);
    Queue::assertPushed(AnalyzeBill::class);
});

it('blocks analyze when receipt is not completed', function () {
    Queue::fake();
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create(['status' => ExtractionStatus::Pending]);
    SessionParticipant::factory()->for($session, 'session')->create();

    $this->actingAs($user)
        ->post(route('sessions.analyze', $session))
        ->assertForbidden();

    Queue::assertNotPushed(AnalyzeBill::class);
});

it('blocks analyze when there are no participants', function () {
    Queue::fake();
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create(['status' => ExtractionStatus::Completed]);

    $this->actingAs($user)
        ->post(route('sessions.analyze', $session))
        ->assertForbidden();

    Queue::assertNotPushed(AnalyzeBill::class);
});

it('records analysis clarification answers and re-dispatches', function () {
    Queue::fake();
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create([
        'status' => ExtractionStatus::Completed,
        'analysis_status' => AnalysisStatus::NeedsClarification,
        'analysis_clarifications' => [
            'round' => 0,
            'answered' => [],
            'pending' => [['id' => 'q1', 'prompt' => 'Quem bebeu a Heineken?', 'type' => 'text', 'options' => []]],
        ],
    ]);

    $this->actingAs($user)
        ->post(route('sessions.analyze.clarify', $session), ['answers' => ['q1' => 'William']])
        ->assertRedirect(route('sessions.show', $session));

    $session->refresh();
    expect($session->analysis_status)->toBe(AnalysisStatus::Processing)
        ->and($session->analysis_clarifications['round'])->toBe(1)
        ->and($session->analysis_clarifications['answered'][0]['answer'])->toBe('William');

    Queue::assertPushed(AnalyzeBill::class);
});
