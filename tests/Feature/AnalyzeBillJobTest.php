<?php

use App\Enums\AnalysisStatus;
use App\Enums\ExtractionStatus;
use App\Events\BillAnalysisCompleted;
use App\Events\ReceiptAnalysisUpdated;
use App\Jobs\AnalyzeBill;
use App\Models\Session;
use App\Models\SessionItem;
use App\Models\SessionParticipant;
use App\Models\User;
use App\Services\Bill\BillSplitter;
use App\Services\Bill\SplitResult;
use Illuminate\Support\Facades\Event;

function seedSplittableSession(): Session
{
    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Completed,
        'subtotal' => 363.40,
        'service_charge' => 36.34,
        'service_charge_percentage' => 10.0,
        'total' => 399.74,
        'food_shared' => true,
        'analysis_status' => AnalysisStatus::Processing,
    ]);

    foreach ([
        ['Parmegiana', 1, 119.90, 119.90, 'food'],
        ['Bife a Cavalo', 3, 50.00, 150.00, 'food'],
        ['Heineken', 3, 9.90, 29.70, 'drink'],
        ['Moscow Mule', 2, 31.90, 63.80, 'drink'],
    ] as $i => [$name, $qty, $unit, $line, $cat]) {
        SessionItem::factory()->for($session, 'session')->create([
            'name' => $name, 'quantity' => $qty, 'unit_price' => $unit,
            'total_price' => $line, 'category' => $cat, 'position' => $i + 1,
        ]);
    }

    SessionParticipant::factory()->for($session, 'session')->create(['name' => 'William', 'text' => '1 moscow mule e 2 heineken']);
    SessionParticipant::factory()->for($session, 'session')->create(['name' => 'Camila', 'text' => '1 heineken e 1 moscow mule']);

    return $session->load('items', 'participants');
}

it('completes analysis and persists per-participant amounts', function () {
    Event::fake([ReceiptAnalysisUpdated::class, BillAnalysisCompleted::class]);

    $session = seedSplittableSession();

    (new AnalyzeBill($session))->handle(app(BillSplitter::class));

    $session->refresh()->load('participants');

    expect($session->analysis_status)->toBe(AnalysisStatus::Completed)
        ->and($session->analysis_result)->toBeArray()
        ->and($session->analyzed_at)->not->toBeNull();

    $totals = $session->participants->pluck('amount_due')->map(fn ($v) => (float) $v);
    expect($totals->filter())->toHaveCount(2)
        ->and(abs($totals->sum() - 399.74))->toBeLessThanOrEqual(0.02);

    Event::assertDispatched(ReceiptAnalysisUpdated::class);
    Event::assertDispatched(BillAnalysisCompleted::class);
});

it('persists what the AI understood (claims by participant name) when it asks for clarification', function () {
    Event::fake([ReceiptAnalysisUpdated::class, BillAnalysisCompleted::class]);

    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Completed,
        'analysis_status' => AnalysisStatus::Processing,
    ]);
    $participant = SessionParticipant::factory()->for($session, 'session')->create(['name' => 'William']);
    $session->load('items', 'participants');

    $this->app->instance(BillSplitter::class, new class implements BillSplitter
    {
        public function split(Session $session, array $participants, bool $foodShared, bool $othersShared = false, array $answered = [], bool $forceFinal = false): SplitResult
        {
            return SplitResult::requestInput(
                questions: [['id' => 'q1', 'prompt' => 'Quem pediu o Moscow Mule?', 'type' => 'text', 'options' => []]],
                raw: ['claims' => [
                    ['participant_id' => $participants[0]['id'], 'items' => [['name' => 'Heineken', 'quantity' => 2.0]]],
                ]],
            );
        }
    });

    (new AnalyzeBill($session))->handle(app(BillSplitter::class));
    $session->refresh();

    expect($session->analysis_status)->toBe(AnalysisStatus::NeedsClarification)
        ->and($session->analysis_clarifications['pending'])->toHaveCount(1)
        ->and($session->analysis_clarifications['understood']['claims'])->toHaveCount(1)
        ->and($session->analysis_clarifications['understood']['claims'][0]['participant_name'])->toBe('William')
        ->and($session->analysis_clarifications['understood']['claims'][0]['items'][0]['name'])->toBe('Heineken');
});
