<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * Optimization Configuration DTO.
 */
final class OptimizationConfig implements ImmutableValue
{
    public readonly array $redis;
    public readonly array $memcached;
    public readonly array $filesystem;
    public readonly array $apcu;

    public function __construct(array $config = [])
    {
        $this->redis = $config['redis'] ?? [];
        $this->memcached = $config['memcached'] ?? [];
        $this->filesystem = $config['filesystem'] ?? [];
        $this->apcu = $config['apcu'] ?? [];
    }

    public function getDriverOptimizations(string $driver): array
    {
        return match ($driver) {
            'redis' => $this->redis,
            'memcached' => $this->memcached,
            'filesystem' => $this->filesystem,
            'apcu' => $this->apcu,
            default => [],
        };
    }

    public function toArray(): array
    {
        return [
            'redis' => $this->redis,
            'memcached' => $this->memcached,
            'filesystem' => $this->filesystem,
            'apcu' => $this->apcu,
        ];
    }
}
