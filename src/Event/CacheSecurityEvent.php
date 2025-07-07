<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

/**
 * PSR-14 compatible security event.
 */
final class CacheSecurityEvent
{
    private bool $propagationStopped = false;

    public function __construct(
        private string $operation,
        private string $key,
        private array $context,
        private mixed $value = null
    ) {
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
