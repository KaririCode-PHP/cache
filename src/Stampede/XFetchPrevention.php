<?php

declare(strict_types=1);

namespace KaririCode\Cache\Stampede;

use KaririCode\Cache\Contract\StampedePrevention;
use KaririCode\Cache\Exception\ConfigurationException;
use Random\IntervalBoundary;
use Random\Randomizer;

/**
 * XFetch probabilistic early recomputation (Vattani, Chierichetti,
 * Lowenstein, VLDB 2015).
 *
 * A caller recomputes when  now − δ·β·ln(U) ≥ expiry,  U ~ Uniform(0, 1],
 * so P(recompute) = exp(−gap / (δ·β)) where gap = expiry − now. The
 * decision needs no coordination: every worker decides independently from
 * the (expiry, δ) stored with the entry. β = 1 is the value the authors
 * recommend; β > 1 recomputes earlier, β < 1 later.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): pure function of time, expiry, cost.
 * Spec V4: stateless service (final readonly class); β validated.
 *
 * Preconditions: beta finite and > 0.
 * Thread-safety: stateless apart from the randomizer engine.
 *
 * Side effects: draws one random number per decision before the expiry.
 *
 * Example:
 *     $xfetch = new XFetchPrevention(beta: 1.0, randomizer: new Randomizer(new Mt19937(42)));
 *
 * References: ADR-002; SPEC-001 §9.
 *
 * @see https://www.vldb.org/pvldb/vol8/p886-vattani.pdf
 */
final readonly class XFetchPrevention implements StampedePrevention
{
    public function __construct(
        private float $beta = 1.0,
        private Randomizer $randomizer = new Randomizer(),
    ) {
        if (!is_finite($beta) || $beta <= 0.0) {
            throw ConfigurationException::invalidOption(option: 'beta', constraint: 'must be a finite number > 0');
        }
    }

    #[\Override]
    public function shouldRecompute(string $key, float $currentTime, float $expiryTime, float $delta): bool
    {
        // $key is unused: XFetch is stateless; each caller decides alone.
        if ($expiryTime <= 0.0) {
            return false;
        }

        if ($currentTime >= $expiryTime) {
            return true;
        }

        if ($delta <= 0.0) {
            return false;
        }

        // U in (0, 1]: ln(U) <= 0, so the left side only moves forward.
        $random = $this->randomizer->getFloat(min: 0.0, max: 1.0, boundary: IntervalBoundary::OpenClosed);

        return $currentTime - ($delta * $this->beta * log($random)) >= $expiryTime;
    }

    #[\Override]
    public function name(): string
    {
        return 'xfetch';
    }
}
