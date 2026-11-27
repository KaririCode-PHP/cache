<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

/**
 * Outcome of EvictionPolicy::evict(): the evicted key, or no key when the
 * policy tracks nothing.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): readonly result.
 * Spec V4: Value Object (final readonly class, named constructors).
 *
 * Preconditions: none.
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     $result = $policy->evict(); if ($result->success) { $adapter->remove((string) $result->evictedKey); }
 *
 * References: SPEC-001 §10.
 *
 * @see https://www.usenix.org/conference/nsdi24/presentation/zhang-yazhuo
 */
final readonly class EvictionResult
{
    public function __construct(
        public ?string $evictedKey,
        public bool $success,
    ) {}

    public static function empty(): self
    {
        return new self(evictedKey: null, success: false);
    }

    public static function evicted(string $key): self
    {
        return new self(evictedKey: $key, success: true);
    }
}
