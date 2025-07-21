<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\Provider;

use KaririCode\Cache\Event\DTO\ListenerDefinition;
use KaririCode\Cache\Exception\InvalidArgumentException;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Advanced PSR-14 compatible listener provider using a type-safe DTO.
 *
 * This implementation manages listeners with advanced features like priorities,
 * wildcard/regex patterns, and conditional execution, all encapsulated
 * within a strongly-typed ListenerDefinition DTO.
 */
final class CacheListenerProvider implements ListenerProviderInterface
{
    /** @var array<string, list<ListenerDefinition>> */
    private array $listeners = [];

    /** @var array<string, list<ListenerDefinition>> */
    private array $wildcardListeners = [];

    /** @var array<string, list<ListenerDefinition>> */
    private array $regexListeners = [];

    /** @var array<string, list<ListenerDefinition>> */
    private array $conditionalListeners = [];

    /**
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        $eventClass = get_class($event);
        $allListeners = [];

        // Collect exact match, parent class, and interface listeners
        $potentialTypes = array_merge([$eventClass], class_parents($event), class_implements($event));
        foreach ($potentialTypes as $type) {
            if (isset($this->listeners[$type])) {
                $allListeners = array_merge($allListeners, $this->listeners[$type]);
            }
        }

        // Collect wildcard listeners
        foreach ($this->wildcardListeners as $pattern => $listeners) {
            if (fnmatch($pattern, $eventClass)) {
                $allListeners = array_merge($allListeners, $listeners);
            }
        }

        // Collect regex listeners
        foreach ($this->regexListeners as $regex => $listeners) {
            if (preg_match($regex, $eventClass)) {
                $allListeners = array_merge($allListeners, $listeners);
            }
        }

        // Collect conditional listeners for the specific event class
        if (isset($this->conditionalListeners[$eventClass])) {
            $allListeners = array_merge($allListeners, $this->conditionalListeners[$eventClass]);
        }

        // Filter out disabled or non-matching conditional listeners
        $activeListeners = array_filter(
            $allListeners,
            fn (ListenerDefinition $def) => $def->isEnabled() && $def->canExecute($event)
        );

        // Sort all collected listeners by priority
        usort($activeListeners, fn (ListenerDefinition $a, ListenerDefinition $b) => $b->priority <=> $a->priority);

        foreach ($activeListeners as $definition) {
            yield $definition->listener;
        }
    }

    public function addListener(string $eventType, callable $listener, int $priority = 0, array $options = []): void
    {
        $this->listeners[$eventType][] = new ListenerDefinition($listener, $priority, $options);
        $this->sortListeners($this->listeners[$eventType]);
    }

    public function addWildcardListener(string $pattern, callable $listener, int $priority = 0): void
    {
        $this->wildcardListeners[$pattern][] = new ListenerDefinition($listener, $priority, [], $pattern);
        $this->sortListeners($this->wildcardListeners[$pattern]);
    }

    public function addRegexListener(string $regex, callable $listener, int $priority = 0): void
    {
        if (false === @preg_match($regex, '')) {
            throw InvalidArgumentException::invalidValue("Invalid regex pattern: {$regex}");
        }
        $this->regexListeners[$regex][] = new ListenerDefinition($listener, $priority, [], null, $regex);
        $this->sortListeners($this->regexListeners[$regex]);
    }

    public function addConditionalListener(string $eventType, callable $listener, callable $predicate, int $priority = 0): void
    {
        $this->conditionalListeners[$eventType][] = new ListenerDefinition($listener, $priority, [], null, null, $predicate);
        $this->sortListeners($this->conditionalListeners[$eventType]);
    }

    /**
     * Sorts a list of ListenerDefinition objects by priority.
     *
     * @param list<ListenerDefinition> $listeners
     */
    private function sortListeners(array &$listeners): void
    {
        usort($listeners, fn (ListenerDefinition $a, ListenerDefinition $b) => $b->priority <=> $a->priority);
    }
}
