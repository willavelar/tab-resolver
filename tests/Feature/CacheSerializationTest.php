<?php

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
it('cache serializable_classes permits Illuminate Collections (pulse reverb cards depend on this)', function () {
    $allowed = config('cache.serializable_classes');

    $serialized = serialize(collect(['app' => collect([1, 2, 3])]));

    $restored = $allowed === null
        ? unserialize($serialized)
        : unserialize($serialized, ['allowed_classes' => $allowed]);

    expect($restored)->toBeInstanceOf(Collection::class)
        ->and($restored->get('app'))->toBeInstanceOf(Collection::class)
        ->and($restored->get('app')->all())->toBe([1, 2, 3]);
});
