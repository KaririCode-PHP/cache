<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serializer;

use KaririCode\Contract\ImmutableValue;

/**
 * SerializerConfiguration - New immutable configuration class.
 */
final class SerializerConfiguration implements ImmutableValue
{
    public function __construct(
        private readonly array $configuration
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->configuration[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->configuration[$key]);
    }

    public function all(): array
    {
        return $this->configuration;
    }

    public function with(string $key, mixed $value): self
    {
        $newConfiguration = $this->configuration;
        $newConfiguration[$key] = $value;

        return new self($newConfiguration);
    }

    public function without(string $key): self
    {
        $newConfiguration = $this->configuration;
        unset($newConfiguration[$key]);

        return new self($newConfiguration);
    }
}
