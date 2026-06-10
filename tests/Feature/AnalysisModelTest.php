<?php

use App\Enums\AnalysisStatus;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts the new analysis fields on the session', function () {
    $session = Session::factory()->for(User::factory())->create([
        'food_shared' => false,
        'analysis_status' => AnalysisStatus::NeedsClarification,
        'analysis_clarifications' => ['round' => 1, 'answered' => [], 'pending' => []],
        'analysis_result' => ['participants' => []],
    ]);

    $fresh = $session->fresh();

    expect($fresh->food_shared)->toBeFalse()
        ->and($fresh->analysis_status)->toBe(AnalysisStatus::NeedsClarification)
        ->and($fresh->analysis_clarifications)->toBeArray()
        ->and($fresh->analysis_result)->toBeArray();
});

it('defaults a new session to shared food and pending analysis', function () {
    $session = Session::factory()->for(User::factory())->create();

    expect($session->fresh()->food_shared)->toBeTrue()
        ->and($session->fresh()->analysis_status)->toBe(AnalysisStatus::Pending);
});

it('casts the new analysis fields on the participant', function () {
    $session = Session::factory()->for(User::factory())->create();
    $participant = SessionParticipant::factory()->for($session, 'session')->create([
        'amount_due' => 123.45,
        'breakdown' => ['total' => 123.45, 'items' => []],
        'transcript' => 'consumi uma cerveja',
    ]);

    $fresh = $participant->fresh();

    expect((float) $fresh->amount_due)->toBe(123.45)
        ->and($fresh->breakdown)->toBeArray()
        ->and($fresh->transcript)->toBe('consumi uma cerveja');
});
