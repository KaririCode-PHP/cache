<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

/**
 * Envelope persisted in the backend by Cache and LayeredCache: the value
 * plus the metadata XFetch needs on every worker (logical expiry, recompute
 * cost).
 *
 * PHP runs share-nothing, so metadata kept in process memory is lost after
 * each request; stored inside the entry, any worker can apply XFetch
 * statelessly (ADR-009). Derived values are query methods that take the
 * current time explicitly, so they stay pure (E-040).
 *
 * ARFA 1.3 P1 (Immutable State Transformation): readonly envelope.
 * Spec V4: Value Object (final readonly class, named constructor withTtl()).
 *
 * Preconditions: timestamps are Unix seconds with microseconds; expiresAt is
 * the logical expiry (the fresh horizon for rememberStale()).
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     $entry = CacheEntry::withTtl($report, ttl: 600, now: microtime(true));
 *
 * References: ADR-009; SPEC-001 §6.
 *
 * @see https://www.vldb.org/pvldb/vol8/p886-vattani.pdf
 */
final readonly class CacheEntry
{
    /**
     * @param list<string> $tags Informational; invalidation is TagAwareCache's job.
     */
    public function __construct(
        public mixed $value,
        public float $createdAt,
        public ?float $expiresAt = null,
        public float $delta = 0.0,
        public array $tags = [],
    ) {}

    /**
     * @param int|null $ttl Logical lifetime in seconds; null = no expiry.
     */
    public static function withTtl(mixed $value, ?int $ttl, float $now): self
    {
        return new self(value: $value, createdAt: $now, expiresAt: null === $ttl ? null : $now + (float) $ttl);
    }

    public function isExpiredAt(float $now): bool
    {
        return null !== $this->expiresAt && $now > $this->expiresAt;
    }

    /**
     * Seconds before the logical expiry (0 once passed); null without expiry.
     */
    public function secondsLeftAt(float $now): ?float
    {
        return null === $this->expiresAt ? null : max(0.0, $this->expiresAt - $now);
    }

    public function ageAt(float $now): float
    {
        return $now - $this->createdAt;
    }
}
