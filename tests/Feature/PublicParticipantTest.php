<?php

use App\Models\Session;
use App\Models\User;

test('a public token is generated automatically when a session is created', function () {
    $session = Session::factory()->for(User::factory())->create();

    expect($session->public_token)->toBeString()
        ->and(strlen($session->public_token))->toBe(32);
});

test('each session gets a distinct public token', function () {
    $a = Session::factory()->for(User::factory())->create();
    $b = Session::factory()->for(User::factory())->create();

    expect($a->public_token)->not->toBe($b->public_token);
});
