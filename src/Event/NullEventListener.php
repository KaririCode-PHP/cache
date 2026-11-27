<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use KaririCode\Cache\Contract\CacheEventListener;

/**
 * No-op CacheEventListener, the default of Cache.
 *
 * ARFA 1.3 P5 (Continuous Observability): replace it to emit signals.
 * Spec V4: stateless service.
 *
 * Preconditions: none.
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     $cache = new Cache($adapter, eventListener: new NullEventListener());
 *
 * References: ADR-007.
 *
 * @see https://en.wikipedia.org/wiki/Null_object_pattern
 */
final readonly class NullEventListener implements CacheEventListener
{
    #[\Override]
    public function onHit(string $key): void {}

    #[\Override]
    public function onMiss(string $key): void {}

    #[\Override]
    public function onWrite(string $key, ?int $ttl): void {}

    #[\Override]
    public function onDelete(string $key): void {}

    #[\Override]
    public function onEviction(string $key, string $policyName): void {}

    #[\Override]
    public function onStampedeRecompute(string $key, float $gapSeconds): void {}
}
