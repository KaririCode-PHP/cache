<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

/**
 * An immutable DTO to represent the state of a circuit breaker for a cache layer.
 */
final class CircuitBreakerState
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half-open';

    public function __construct(
        public readonly int $failures = 0,
        public readonly ?int $lastFailureTimestamp = null,
        public readonly string $state = self::STATE_CLOSED
    ) {
    }

    public function recordSuccess(): self
    {
        if (self::STATE_HALF_OPEN === $this->state) {
            // Successful operation in half-open state closes the circuit
            return new self();
        }

        return $this;
    }

    public function recordFailure(): self
    {
        return new self($this->failures + 1, time(), $this->state);
    }

    public function openCircuit(): self
    {
        return new self($this->failures, $this->lastFailureTimestamp, self::STATE_OPEN);
    }

    public function halfOpenCircuit(): self
    {
        return new self(0, null, self::STATE_HALF_OPEN);
    }
}
