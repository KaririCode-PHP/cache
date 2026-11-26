<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO to represent a single cache layer's configuration.
 *
 * This Value Object holds the driver, specific config, and priority
 * for one layer within a layered cache setup.
 */
final class LayerConfig implements ImmutableValue
{
    /**
     * @param string $driver The driver for this layer (e.g., 'apcu', 'redis').
     * @param array<string, mixed> $config the specific configuration for this layer's driver
     * @param int $priority the priority of this layer (lower numbers are accessed first)
     */
    public function __construct(
        public readonly string $driver,
        public readonly array $config,
        public readonly int $priority
    ) {
    }
}
