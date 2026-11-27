<?php

declare(strict_types=1);

namespace KaririCode\Cache\Clock;

use Psr\Clock\ClockInterface;

/**
 * Wall clock used by default wherever the package needs "now": expiry of
 * memory and file entries, XFetch decisions, envelope timestamps and PSR
 * TTL conversion. Tests inject a frozen clock instead (Spec V4 §19.2).
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): time arrives through PSR-20.
 * Spec V4: stateless service (final readonly class).
 *
 * Preconditions: none.
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: reads the system clock.
 *
 * Example:
 *     $cache = new Cache(new ArrayAdapter(), clock: new SystemClock());
 *
 * References: ADR-014; SPEC-001 §9.
 *
 * @see https://www.php-fig.org/psr/psr-20/
 */
final readonly class SystemClock implements ClockInterface
{
    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
