<?php

use App\Enums\ExtractionStatus;

test('extraction status enum has the expected cases', function () {
    expect(ExtractionStatus::Pending->value)->toBe('pending');
    expect(ExtractionStatus::Processing->value)->toBe('processing');
    expect(ExtractionStatus::Completed->value)->toBe('completed');
    expect(ExtractionStatus::Failed->value)->toBe('failed');
});
