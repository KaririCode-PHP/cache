<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

/**
 * An immutable Value Object representing the state of a circuit breaker.
 *
 * This class encapsulates all the logic for state transitions (closed, open, half-open),
 * failure thresholds, and retry timeouts, making it a self-contained and robust
 * component for resilience patterns.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class CircuitBreakerState
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half-open';
    private const FAILURE_THRESHOLD = 5;
    private const OPEN_TIMEOUT_SECONDS = 60;

    /**
     * @param int $failures the current consecutive failure count
     * @param int|null $lastFailureTimestamp the Unix timestamp of the last failure
     * @param string $state the current state (closed, open, or half-open)
     */
    public function __construct(
        public readonly int $failures = 0,
        public readonly ?int $lastFailureTimestamp = null,
        public readonly string $state = self::STATE_CLOSED,
    ) {
    }

    /**
     * Exposes the current consecutive failure count.
     */
    public function getFailureCount(): int
    {
        return $this->failures;
    }

    /**
     * Records a successful operation and transitions the state if necessary.
     * If the circuit was half-open, a success closes it.
     */
    public function recordSuccess(): self
    {
        // A successful operation when half-open resets the circuit fully.
        if ($this->isHalfOpen()) {
            return new self();
        }

        // No change required for CLOSED state.
        return $this;
    }

    /**
     * Records a failure and returns a new state instance.
     * The state itself is not automatically opened; call open() explicitly if desired.
     */
    public function recordFailure(): self
    {
        return new self($this->failures + 1, time(), $this->state);
    }

    /**
     * Forces a transition to OPEN state.
     */
    public function open(): self
    {
        // Ensure we have a timestamp marking when the circuit opened.
        return new self($this->failures, $this->lastFailureTimestamp ?? time(), self::STATE_OPEN);
    }

    /**
     * Forces a transition to HALF-OPEN state, resetting failure counters.
     */
    public function halfOpen(): self
    {
        return new self(0, null, self::STATE_HALF_OPEN);
    }

    // === State inspection helpers ===

    public function isOpen(): bool
    {
        return self::STATE_OPEN === $this->state;
    }

    public function isClosed(): bool
    {
        return self::STATE_CLOSED === $this->state;
    }

    public function isHalfOpen(): bool
    {
        return self::STATE_HALF_OPEN === $this->state;
    }

    // === Transition decision helpers ===

    public function shouldOpen(): bool
    {
        return $this->failures >= self::FAILURE_THRESHOLD;
    }

    /**
     * Determines if the circuit should reset from OPEN to HALF-OPEN after a timeout.
     */
    public function shouldAttemptReset(int $timeoutSeconds): bool
    {
        if (null === $this->lastFailureTimestamp) {
            return false;
        }

        return (time() - $this->lastFailureTimestamp) > $timeoutSeconds;
    }

    /**
     * Determines if a retry can be attempted while OPEN.
     */
    public function canAttemptRetry(): bool
    {
        if (null === $this->lastFailureTimestamp) {
            return true;
        }

        return (time() - $this->lastFailureTimestamp) > self::OPEN_TIMEOUT_SECONDS;
    }
}
