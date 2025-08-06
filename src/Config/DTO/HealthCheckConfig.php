<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * Health Check Configuration DTO.
 */
final class HealthCheckConfig implements ImmutableValue
{
    public readonly bool $enabled;
    public readonly int $interval;
    public readonly int $timeout;
    public readonly array $storesToCheck;
    public readonly int $failureThreshold;
    public readonly int $recoveryThreshold;

    public function __construct(array $config = [])
    {
        $this->enabled = $config['enabled'] ?? true;
        $this->interval = $config['interval'] ?? 300; // 5 minutes
        $this->timeout = $config['timeout'] ?? 5;
        $this->storesToCheck = $config['stores_to_check'] ?? ['redis', 'memcached'];
        $this->failureThreshold = $config['failure_threshold'] ?? 3;
        $this->recoveryThreshold = $config['recovery_threshold'] ?? 2;
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'interval' => $this->interval,
            'timeout' => $this->timeout,
            'stores_to_check' => $this->storesToCheck,
            'failure_threshold' => $this->failureThreshold,
            'recovery_threshold' => $this->recoveryThreshold,
        ];
    }
}
