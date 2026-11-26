<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO that holds driver-specific optimization settings.
 *
 * This Value Object provides a structured way to access optimization
 * configurations for different cache backends like Redis or Memcached.
 */
final class OptimizationConfig implements ImmutableValue
{
    /**
     * @param array<string, mixed> $redis optimization settings for the Redis driver
     * @param array<string, mixed> $memcached optimization settings for the Memcached driver
     * @param array<string, mixed> $filesystem optimization settings for the Filesystem driver
     * @param array<string, mixed> $apcu optimization settings for the APCu driver
     */
    private function __construct(
        public readonly array $redis,
        public readonly array $memcached,
        public readonly array $filesystem,
        public readonly array $apcu
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
            redis: (array) ($config['redis'] ?? []),
            memcached: (array) ($config['memcached'] ?? []),
            filesystem: (array) ($config['filesystem'] ?? []),
            apcu: (array) ($config['apcu'] ?? [])
        );
    }

    /**
     * Gets the specific optimization settings for a given driver name.
     *
     * @param string $driver The name of the driver (e.g., 'redis').
     *
     * @return array<string, mixed> the optimization settings for that driver
     */
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
}
