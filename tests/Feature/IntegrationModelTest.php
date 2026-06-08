<?php

use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('encrypts the api_key at rest and decrypts transparently', function () {
    $integration = Integration::create([
        'provider' => 'openai',
        'api_key' => 'sk-secret-1234',
        'receipt_model' => 'gpt-4o-mini',
        'audio_model' => 'whisper-1',
    ]);

    $raw = DB::table('integrations')->where('id', $integration->id)->value('api_key');

    expect($raw)->not->toBe('sk-secret-1234');
    expect(Crypt::decryptString($raw))->toBe('sk-secret-1234');
    expect($integration->fresh()->api_key)->toBe('sk-secret-1234');
});

it('returns a singleton via current() defaulting to the openai provider', function () {
    $a = Integration::current();
    $a->fill([
        'api_key' => 'k1',
        'receipt_model' => 'gpt-4o-mini',
        'audio_model' => 'whisper-1',
    ])->save();

    $b = Integration::current();

    expect($b->exists)->toBeTrue();
    expect($b->id)->toBe($a->id);
    expect($b->provider)->toBe('openai');
    expect($b->receipt_model)->toBe('gpt-4o-mini');
    expect($b->audio_model)->toBe('whisper-1');
});
