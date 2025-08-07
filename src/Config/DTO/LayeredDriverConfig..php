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
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class LayeredDriverConfig implements ImmutableValue
{
    public readonly bool $promotionEnabled;
    public readonly int $promotionThreshold;
    public readonly bool $circuitBreakerEnabled;
    public readonly int $maxFailures;
    public readonly int $circuitTimeout;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->promotionEnabled = (bool) ($config['promotion_enabled'] ?? true);
        $this->promotionThreshold = (int) ($config['promotion_threshold'] ?? 3);
        $this->circuitBreakerEnabled = (bool) ($config['circuit_breaker_enabled'] ?? true);
        $this->maxFailures = (int) ($config['max_failures'] ?? 5);
        $this->circuitTimeout = (int) ($config['circuit_timeout'] ?? 60);

        $this->validate();
    }

    /**
     * Creates an instance from a configuration array.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'promotion_enabled' => $this->promotionEnabled,
            'promotion_threshold' => $this->promotionThreshold,
            'circuit_breaker_enabled' => $this->circuitBreakerEnabled,
            'max_failures' => $this->maxFailures,
            'circuit_timeout' => $this->circuitTimeout,
        ];
    }
}
