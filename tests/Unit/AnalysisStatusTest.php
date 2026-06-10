<?php

use App\Enums\AnalysisStatus;

it('has the five lifecycle states', function () {
    expect(AnalysisStatus::Pending->value)->toBe('pending')
        ->and(AnalysisStatus::Processing->value)->toBe('processing')
        ->and(AnalysisStatus::Completed->value)->toBe('completed')
        ->and(AnalysisStatus::NeedsClarification->value)->toBe('needs_clarification')
        ->and(AnalysisStatus::Failed->value)->toBe('failed');
});
