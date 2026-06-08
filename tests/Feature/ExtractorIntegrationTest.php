<?php

use App\Models\Integration;
use App\Services\Receipt\PrismReceiptExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies the database api key and receipt model to the prism config at runtime', function () {
    config(['prism.providers.openai.api_key' => 'env-key']);
    config(['services.openai.receipt_model' => 'env-model']);

    Integration::create([
        'provider' => 'openai',
        'api_key' => 'db-secret-key',
        'receipt_model' => 'db-model',
        'audio_model' => 'whisper-1',
    ]);

    $extractor = new PrismReceiptExtractor;
    $resolved = $extractor->resolveCredentials();

    expect($resolved['model'])->toBe('db-model');
    expect(config('prism.providers.openai.api_key'))->toBe('db-secret-key');
});

it('falls back to env config when no integration is stored', function () {
    config(['prism.providers.openai.api_key' => 'env-key']);
    config(['services.openai.receipt_model' => 'env-model']);

    $extractor = new PrismReceiptExtractor;
    $resolved = $extractor->resolveCredentials();

    expect($resolved['model'])->toBe('env-model');
    expect(config('prism.providers.openai.api_key'))->toBe('env-key');
});
