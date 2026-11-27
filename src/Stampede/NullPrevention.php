<?php

declare(strict_types=1);

namespace KaririCode\Cache\Stampede;

use KaririCode\Cache\Contract\StampedePrevention;

/**
 * Stampede strategy that never recomputes early: entries are recomputed
 * on a miss or when their logical expiry has passed.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): Null Object behind the StampedePrevention port.
 * Spec V4: stateless service.
 *
 * Preconditions: none.
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     $cache = new Cache($adapter, stampedePrevention: new NullPrevention());
 *
 * References: ADR-002.
 *
 * @see https://en.wikipedia.org/wiki/Null_object_pattern
 */
final readonly class NullPrevention implements StampedePrevention
{
    #[\Override]
    public function shouldRecompute(string $key, float $currentTime, float $expiryTime, float $delta): bool
    {
        return $currentTime >= $expiryTime;
    }

    #[\Override]
    public function name(): string
    {
        return 'none';
    }
}
