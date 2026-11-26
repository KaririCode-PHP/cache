<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO for all LayeredDriver configurations.
 *
 * This class acts as a Value Object, encapsulating all settings related to the
 * layered cache's behavior, such as cache promotion and circuit breaker rules.
 * It provides type safety, default values, and self-validation.
 */
final class LayeredDriverConfig implements ImmutableValue
{
    private const DEFAULT_PROMOTION_THRESHOLD = 3;
    private const DEFAULT_MAX_FAILURES = 5;
    private const DEFAULT_CIRCUIT_TIMEOUT_SECONDS = 60;

    /**
     * @param bool $promotionEnabled toggles the promotion of frequently accessed keys to faster layers
     * @param int $promotionThreshold the number of hits required before a key is promoted
     * @param bool $circuitBreakerEnabled toggles the circuit breaker for failing cache layers
     * @param int $maxFailures the number of consecutive failures before opening the circuit for a layer
     * @param int $circuitTimeout the time in seconds a circuit remains open before moving to half-open
     */
    private function __construct(
        public readonly bool $promotionEnabled,
        public readonly int $promotionThreshold,
        public readonly bool $circuitBreakerEnabled,
        public readonly int $maxFailures,
        public readonly int $circuitTimeout
    ) {
        $this->validate();
    }

    /**
     * Creates an instance from a configuration array.
     *
     * @param array<string, mixed> $config the raw configuration array
     */
    public static function fromConfig(array $config = []): self
    {
        return new self(
            promotionEnabled: (bool) ($config['promotion_enabled'] ?? true),
            promotionThreshold: (int) ($config['promotion_threshold'] ?? self::DEFAULT_PROMOTION_THRESHOLD),
            circuitBreakerEnabled: (bool) ($config['circuit_breaker_enabled'] ?? true),
            maxFailures: (int) ($config['max_failures'] ?? self::DEFAULT_MAX_FAILURES),
            circuitTimeout: (int) ($config['circuit_timeout'] ?? self::DEFAULT_CIRCUIT_TIMEOUT_SECONDS)
        );
    }

    /**
     * Validates the configuration properties to ensure they are logical.
     *
     * @throws ConfigurationException if any configuration is invalid
     */
    private function validate(): void
    {
        if ($this->promotionThreshold < 1) {
            throw ConfigurationException::invalidOption('promotion_threshold', $this->promotionThreshold, 'an integer greater than 0');
        }
        if ($this->maxFailures < 1) {
            throw ConfigurationException::invalidOption('max_failures', $this->maxFailures, 'an integer greater than 0');
        }
        if ($this->circuitTimeout < 1) {
            throw ConfigurationException::invalidOption('circuit_timeout', $this->circuitTimeout, 'an integer greater than 0');
        }
    }
}
