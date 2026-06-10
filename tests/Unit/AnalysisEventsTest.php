<?php

use App\Events\BillAnalysisCompleted;
use App\Events\ReceiptAnalysisUpdated;

it('broadcasts analysis updates on the owner private channel', function () {
    $event = new ReceiptAnalysisUpdated('sess-1', 'completed');

    expect($event->broadcastOn()->name)->toBe('private-bill-session.sess-1')
        ->and($event->broadcastAs())->toBe('analysis.updated');
});

it('broadcasts a public completion signal on the public channel', function () {
    $event = new BillAnalysisCompleted('sess-1');

    expect($event->broadcastOn()->name)->toBe('bill-session.sess-1.public')
        ->and($event->broadcastAs())->toBe('analysis.completed');
});
