<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO that holds configuration for cache health checks.
 *
 * It defines whether checks are enabled, their frequency, timeout, and the
 * thresholds for marking a store as unhealthy or recovered.
 */
final class HealthCheckConfig implements ImmutableValue
{
    private const DEFAULT_INTERVAL_SECONDS = 300;
    private const DEFAULT_TIMEOUT_SECONDS = 5;
    private const DEFAULT_FAILURE_THRESHOLD = 3;
    private const DEFAULT_RECOVERY_THRESHOLD = 2;

    /**
     * @param bool $enabled toggles the health check system on or off
     * @param int $interval the interval in seconds between health checks
     * @param int $timeout the timeout in seconds for a single health check probe
     * @param array<string> $storesToCheck a list of store names to be checked
     * @param int $failureThreshold number of consecutive failures to mark a store as down
     * @param int $recoveryThreshold number of consecutive successes to mark a store as recovered
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly int $interval,
        public readonly int $timeout,
        public readonly array $storesToCheck,
        public readonly int $failureThreshold,
        public readonly int $recoveryThreshold
    ) {
    }

    /**
     * Creates an instance from a configuration array.
     *
     * @param array<string, mixed> $config the raw configuration array
     */
    public static function fromConfig(array $config = []): self
    {
        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            interval: (int) ($config['interval'] ?? self::DEFAULT_INTERVAL_SECONDS),
            timeout: (int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS),
            storesToCheck: (array) ($config['stores_to_check'] ?? ['redis', 'memcached']),
            failureThreshold: (int) ($config['failure_threshold'] ?? self::DEFAULT_FAILURE_THRESHOLD),
            recoveryThreshold: (int) ($config['recovery_threshold'] ?? self::DEFAULT_RECOVERY_THRESHOLD)
        );
    }
}
