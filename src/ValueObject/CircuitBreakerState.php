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
        public readonly string $state = self::STATE_CLOSED
    ) {
    }

    /**
     * Records a successful operation and transitions the state if necessary.
     * If the circuit was half-open, a success closes it.
     *
     * @return self a new instance representing the updated state
     */
    public function recordSuccess(): self
    {
        if ($this->isHalfOpen()) {
            // A successful operation in a half-open state resets the circuit.
            return new self();
        }

        // If closed, a success doesn't change the state.
        return $this;
    }

    /**
     * Records a failure and returns a new state.
     * The state does not automatically open; use `open()` for that transition.
     *
     * @return self a new instance representing the updated state
     */
    public function recordFailure(): self
    {
        return new self($this->failures + 1, time(), $this->state);
    }

    /**
     * Transitions the state to open.
     *
     * @return self a new instance in the 'open' state
     */
    public function open(): self
    {
        return new self($this->failures, $this->lastFailureTimestamp, self::STATE_OPEN);
    }

    /**
     * Transitions the state to half-open.
     *
     * @return self a new instance in the 'half-open' state, with failures reset
     */
    public function halfOpen(): self
    {
        return new self(0, null, self::STATE_HALF_OPEN);
    }

    /**
     * Checks if the circuit is currently open.
     */
    public function isOpen(): bool
    {
        return self::STATE_OPEN === $this->state;
    }

    /**
     * Checks if the circuit is currently closed.
     */
    public function isClosed(): bool
    {
        return self::STATE_CLOSED === $this->state;
    }

    /**
     * Checks if the circuit is currently half-open.
     */
    public function isHalfOpen(): bool
    {
        return self::STATE_HALF_OPEN === $this->state;
    }

    /**
     * Determines if the circuit should be opened based on the failure threshold.
     */
    public function shouldOpen(): bool
    {
        return $this->failures >= self::FAILURE_THRESHOLD;
    }

    /**
     * Determines if a retry can be attempted when the circuit is open.
     * This is true if the timeout period has passed since the last failure.
     */
    public function canAttemptRetry(): bool
    {
        if (null === $this->lastFailureTimestamp) {
            return true;
        }

        return (time() - $this->lastFailureTimestamp) > self::OPEN_TIMEOUT_SECONDS;
    }
}
