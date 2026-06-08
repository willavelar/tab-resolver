<?php

use App\Models\Integration;
use App\Services\Receipt\PrismReceiptExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies the database api key and model to the prism config at runtime', function () {
    config(['prism.providers.anthropic.api_key' => 'env-key']);
    config(['services.anthropic.receipt_model' => 'env-model']);

    Integration::create([
        'provider' => 'anthropic',
        'api_key' => 'db-secret-key',
        'model' => 'db-model',
    ]);

    $extractor = new PrismReceiptExtractor;
    $resolved = $extractor->resolveCredentials();

    expect($resolved['model'])->toBe('db-model');
    expect(config('prism.providers.anthropic.api_key'))->toBe('db-secret-key');
});

it('falls back to env config when no integration is stored', function () {
    config(['prism.providers.anthropic.api_key' => 'env-key']);
    config(['services.anthropic.receipt_model' => 'env-model']);

    $extractor = new PrismReceiptExtractor;
    $resolved = $extractor->resolveCredentials();

    expect($resolved['model'])->toBe('env-model');
    expect(config('prism.providers.anthropic.api_key'))->toBe('env-key');
});
