<?php

use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('encrypts the api_key at rest and decrypts transparently', function () {
    $integration = Integration::create([
        'provider' => 'anthropic',
        'api_key' => 'sk-ant-secret-1234',
        'model' => 'claude-sonnet-4-5-20250929',
    ]);

    $raw = DB::table('integrations')->where('id', $integration->id)->value('api_key');

    expect($raw)->not->toBe('sk-ant-secret-1234');
    expect(Crypt::decryptString($raw))->toBe('sk-ant-secret-1234');
    expect($integration->fresh()->api_key)->toBe('sk-ant-secret-1234');
});

it('returns a singleton via current()', function () {
    $a = Integration::current();
    $a->fill(['api_key' => 'k1', 'model' => 'm1'])->save();

    $b = Integration::current();

    expect($b->exists)->toBeTrue();
    expect($b->id)->toBe($a->id);
    expect($b->model)->toBe('m1');
});
