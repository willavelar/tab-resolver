<?php

use App\Enums\AnalysisStatus;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;

it('shows only the current device participant breakdown on the public page', function () {
    $session = Session::factory()->for(User::factory())->create([
        'analysis_status' => AnalysisStatus::Completed,
    ]);

    SessionParticipant::factory()->for($session, 'session')->create([
        'name' => 'William',
        'submitter_token' => 'token-mine',
        'amount_due' => 205.32,
        'breakdown' => ['name' => 'William', 'total' => 205.32, 'items' => []],
    ]);
    SessionParticipant::factory()->for($session, 'session')->create([
        'name' => 'Camila',
        'submitter_token' => 'token-other',
        'amount_due' => 194.43,
        'breakdown' => ['name' => 'Camila', 'total' => 194.43, 'items' => []],
    ]);

    $response = $this->withUnencryptedCookie('tr_pid', 'token-mine')
        ->get(route('public.sessions.show', $session->public_token));

    $response->assertInertia(fn ($page) => $page
        ->where('session.analysis_status', 'completed')
        ->where('myBreakdown.total', 205.32)
        ->where('myBreakdown.name', 'William')
    );

    // Camila's amount must NOT appear anywhere in the payload.
    expect($response->getContent())->not->toContain('194.43');
});

it('exposes no breakdown to a device that did not submit', function () {
    $session = Session::factory()->for(User::factory())->create([
        'analysis_status' => AnalysisStatus::Completed,
    ]);
    SessionParticipant::factory()->for($session, 'session')->create([
        'submitter_token' => 'someone-else', 'amount_due' => 50.0,
        'breakdown' => ['total' => 50.0],
    ]);

    $this->get(route('public.sessions.show', $session->public_token))
        ->assertInertia(fn ($page) => $page->where('myBreakdown', null));
});
