<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/*
 * Pulse's Reverb cards (reverb.connections / reverb.messages) memoize their
 * dashboard query as a nested Illuminate\Support\Collection through the cache
 * (Laravel\Pulse\Livewire\Concerns\RemembersQueries). On Redis, the cache reads
 * the value back via Illuminate\Cache\RedisStore::unserialize(), which honours
 * config('cache.serializable_classes') as the unserialize() allowed_classes list:
 *
 *   if ($this->serializableClasses !== null) {
 *       return unserialize($value, ['allowed_classes' => $this->serializableClasses]);
 *   }
 *
 * If that allowlist excludes Collection (e.g. the hardened `false` => "no objects"),
 * the value comes back as __PHP_Incomplete_Class and /pulse crashes at
 * connections.blade.php with "called a method on an incomplete object".
 *
 * This test replicates that exact decode path against the real config so the
 * allowlist can never silently regress to a value that breaks the Pulse cards.
 */
/**
 * Replicates Illuminate\Cache\RedisStore::unserialize() against the real config.
 *
 * @return mixed
 */
function decodeThroughCacheAllowlist(string $serialized)
{
    $allowed = config('cache.serializable_classes');

    return $allowed === null
        ? unserialize($serialized)
        : unserialize($serialized, ['allowed_classes' => $allowed]);
}

it('cache serializable_classes permits Illuminate Collections (pulse reverb cards depend on this)', function () {
    $restored = decodeThroughCacheAllowlist(serialize(collect(['app' => collect([1, 2, 3])])));

    expect($restored)->toBeInstanceOf(Collection::class)
        ->and($restored->get('app'))->toBeInstanceOf(Collection::class)
        ->and($restored->get('app')->all())->toBe([1, 2, 3]);
});

it('cache serializable_classes permits the full pulse servers card row shape', function () {
    // Mirrors exactly what the Pulse Servers card caches: a Collection of stdClass
    // rows, each holding nested Collections and a CarbonImmutable timestamp.
    $row = (object) [
        'name' => 'web-1',
        'cpu' => collect([10, 20]),
        'updated_at' => CarbonImmutable::createFromTimestamp(1_700_000_000),
    ];
    $restored = decodeThroughCacheAllowlist(serialize(collect(['web-1' => $row])));

    expect($restored)->toBeInstanceOf(Collection::class)
        ->and($restored->get('web-1'))->toBeInstanceOf(stdClass::class)
        ->and($restored->get('web-1')->cpu)->toBeInstanceOf(Collection::class)
        ->and($restored->get('web-1')->updated_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($restored->get('web-1')->updated_at->getTimestamp())->toBe(1_700_000_000);
});
