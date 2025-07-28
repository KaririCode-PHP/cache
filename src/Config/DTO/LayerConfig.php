<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO to represent a cache layer's configuration.
 */
final class LayerConfig implements ImmutableValue
{
    public function __construct(
        public readonly string $driver,
        public readonly array $config,
        public readonly int $priority
    ) {
    }
}
