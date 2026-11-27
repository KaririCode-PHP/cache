<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

/**
 * Envelope persisted in the backend by TagAwareCache.
 *
 * Carries the value, the tag versions captured before the value was
 * computed, and the XFetch metadata (logical expiry and recompute cost).
 * A dedicated class replaces the 4.0 array envelope, which could not be
 * told apart from a user array shaped the same way.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): readonly envelope.
 * Spec V4: DPO (final readonly class, no behaviour).
 *
 * Preconditions: tagVersions were read before the value was computed.
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     $entry = new TaggedEntry($value, ['user:42' => $version], expiresAt: $now + 600);
 *
 * References: ADR-012.
 *
 * @see https://www.vldb.org/pvldb/vol8/p886-vattani.pdf
 */
final readonly class TaggedEntry
{
    /**
     * @param array<string, int> $tagVersions tag => version at write time
     */
    public function __construct(
        public mixed $value,
        public array $tagVersions = [],
        public ?float $expiresAt = null,
        public float $delta = 0.0,
    ) {}
}
