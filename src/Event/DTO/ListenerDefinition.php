<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\DTO;

/**
 * A DTO to provide a strongly-typed structure for listener registrations.
 * This encapsulates all data related to a single listener, improving code
 * clarity and maintainability over simple associative arrays.
 */
final class ListenerDefinition
{
    public readonly string $id;

    /**
     * @param callable $listener the listener callback
     * @param int $priority the execution priority (higher numbers execute first)
     * @param array<string, mixed> $options Optional metadata, e.g., ['enabled' => false].
     * @param string|null $pattern the wildcard pattern for wildcard listeners
     * @param string|null $regex the regex pattern for regex listeners
     * @param callable|null $predicate the predicate function for conditional listeners
     */
    public function __construct(
        public readonly mixed $listener,
        public readonly int $priority = 0,
        public readonly array $options = [],
        public readonly ?string $pattern = null,
        public readonly ?string $regex = null,
        public readonly mixed $predicate = null
    ) {
        $this->id = spl_object_hash((object) $this->listener);
    }

    /**
     * Checks if the listener is currently enabled based on its options.
     */
    public function isEnabled(): bool
    {
        return ($this->options['enabled'] ?? true) === true;
    }

    /**
     * Checks if the listener's predicate allows it to execute for the given event.
     */
    public function canExecute(object $event): bool
    {
        if (null === $this->predicate) {
            return true; // No predicate, always allowed.
        }

        return call_user_func($this->predicate, $event);
    }
}
