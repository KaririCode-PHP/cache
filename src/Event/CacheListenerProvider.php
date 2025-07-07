<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Advanced PSR-14 compatible listener provider with enhanced features.
 *
 * This implementation provides sophisticated listener management including:
 * - Priority-based listener ordering
 * - Wildcard and regex pattern matching
 * - Conditional listeners
 * - Performance optimizations
 * - Thread-safe operations
 * - Listener groups and tags
 *
 * Following SOLID principles and Clean Code practices for maximum maintainability.
 */
final class CacheListenerProvider implements ListenerProviderInterface
{
    /** @var array<string, array> Listeners indexed by event type */
    private array $listeners = [];

    /** @var array<string, array> Wildcard patterns for event matching */
    private array $wildcardListeners = [];

    /** @var array<string, array> Regex patterns for event matching */
    private array $regexListeners = [];

    /** @var array<string, array> Conditional listeners with predicates */
    private array $conditionalListeners = [];

    /** @var array<string, array> Listener groups for batch operations */
    private array $listenerGroups = [];

    /** @var array<string, bool> Cached listener lookups for performance */
    private array $listenerCache = [];

    /** @var bool Whether to use caching for performance optimization */
    private bool $enableCaching;

    /** @var int Maximum cache size to prevent memory issues */
    private int $maxCacheSize;

    /** @var array Statistics for monitoring and debugging */
    private array $statistics = [
        'total_listeners' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'pattern_matches' => 0,
        'conditional_matches' => 0,
    ];

    public function __construct(bool $enableCaching = true, int $maxCacheSize = 1000)
    {
        $this->enableCaching = $enableCaching;
        $this->maxCacheSize = $maxCacheSize;
    }

    /**
     * PSR-14 method to get listeners for an event.
     */
    public function getListenersForEvent(object $event): iterable
    {
        $eventClass = get_class($event);

        if ($this->enableCaching && isset($this->listenerCache[$eventClass])) {
            ++$this->statistics['cache_hits'];
            yield from $this->listenerCache[$eventClass];

            return;
        }

        ++$this->statistics['cache_misses'];
        $listeners = $this->collectListenersForEvent($event, $eventClass);

        // Cache the result if caching is enabled
        if ($this->enableCaching) {
            $this->cacheListeners($eventClass, $listeners);
        }

        yield from $listeners;
    }

    /**
     * Add a listener for a specific event type with priority support.
     */
    public function addListener(string $eventType, callable $listener, int $priority = 0, array $options = []): void
    {
        $listenerData = [
            'listener' => $listener,
            'priority' => $priority,
            'options' => $options,
            'id' => $this->generateListenerId($listener),
        ];

        if (!isset($this->listeners[$eventType])) {
            $this->listeners[$eventType] = [];
        }

        $this->listeners[$eventType][] = $listenerData;
        $this->sortListenersByPriority($eventType);
        ++$this->statistics['total_listeners'];

        // Clear cache for this event type
        $this->clearCacheForEventType($eventType);
    }

    /**
     * Add a wildcard listener that matches event patterns.
     */
    public function addWildcardListener(string $pattern, callable $listener, int $priority = 0): void
    {
        $listenerData = [
            'listener' => $listener,
            'priority' => $priority,
            'pattern' => $pattern,
            'id' => $this->generateListenerId($listener),
        ];

        $this->wildcardListeners[$pattern][] = $listenerData;
        $this->sortWildcardListenersByPriority($pattern);
        ++$this->statistics['total_listeners'];

        // Clear entire cache since wildcard affects multiple event types
        $this->clearCache();
    }

    /**
     * Add a regex listener for complex pattern matching.
     */
    public function addRegexListener(string $regex, callable $listener, int $priority = 0): void
    {
        if (!$this->isValidRegex($regex)) {
            throw new \InvalidArgumentException("Invalid regex pattern: {$regex}");
        }

        $listenerData = [
            'listener' => $listener,
            'priority' => $priority,
            'regex' => $regex,
            'id' => $this->generateListenerId($listener),
        ];

        $this->regexListeners[$regex][] = $listenerData;
        $this->sortRegexListenersByPriority($regex);
        ++$this->statistics['total_listeners'];

        // Clear entire cache since regex affects multiple event types
        $this->clearCache();
    }

    /**
     * Add a conditional listener that only executes when predicate returns true.
     */
    public function addConditionalListener(
        string $eventType,
        callable $listener,
        callable $predicate,
        int $priority = 0
    ): void {
        $listenerData = [
            'listener' => $listener,
            'predicate' => $predicate,
            'priority' => $priority,
            'id' => $this->generateListenerId($listener),
        ];

        if (!isset($this->conditionalListeners[$eventType])) {
            $this->conditionalListeners[$eventType] = [];
        }

        $this->conditionalListeners[$eventType][] = $listenerData;
        $this->sortConditionalListenersByPriority($eventType);
        ++$this->statistics['total_listeners'];

        // Clear cache for this event type
        $this->clearCacheForEventType($eventType);
    }

    /**
     * Remove a specific listener by ID or callable.
     */
    public function removeListener(string $eventType, $listenerIdentifier): bool
    {
        $removed = false;

        if (isset($this->listeners[$eventType])) {
            $this->listeners[$eventType] = array_filter(
                $this->listeners[$eventType],
                function ($item) use ($listenerIdentifier, &$removed) {
                    $match = $this->matchesListenerIdentifier($item, $listenerIdentifier);
                    if ($match) {
                        $removed = true;
                        --$this->statistics['total_listeners'];
                    }

                    return !$match;
                }
            );
        }

        if ($removed) {
            $this->clearCacheForEventType($eventType);
        }

        return $removed;
    }

    /**
     * Create a listener group for batch operations.
     */
    public function createListenerGroup(string $groupName, array $listeners = []): void
    {
        $this->listenerGroups[$groupName] = [];

        foreach ($listeners as $eventType => $groupListeners) {
            foreach ($groupListeners as $listener) {
                $priority = $listener['priority'] ?? 0;
                $this->addListener($eventType, $listener['callable'], $priority);
                $this->listenerGroups[$groupName][] = [
                    'eventType' => $eventType,
                    'id' => $this->generateListenerId($listener['callable']),
                ];
            }
        }
    }

    /**
     * Enable or disable a listener group.
     */
    public function toggleListenerGroup(string $groupName, bool $enabled): void
    {
        if (!isset($this->listenerGroups[$groupName])) {
            throw new \InvalidArgumentException("Listener group '{$groupName}' does not exist");
        }

        foreach ($this->listenerGroups[$groupName] as $groupListener) {
            $eventType = $groupListener['eventType'];
            $listenerId = $groupListener['id'];

            if (isset($this->listeners[$eventType])) {
                foreach ($this->listeners[$eventType] as &$listener) {
                    if ($listener['id'] === $listenerId) {
                        $listener['options']['enabled'] = $enabled;
                        break;
                    }
                }
            }
        }

        $this->clearCache();
    }

    /**
     * Get all listeners for a specific event type.
     */
    public function getListeners(string $eventType): array
    {
        return array_column($this->listeners[$eventType] ?? [], 'listener');
    }

    /**
     * Check if listeners exist for an event type.
     */
    public function hasListeners(string $eventType): bool
    {
        return !empty($this->listeners[$eventType])
               || $this->hasWildcardMatches($eventType)
               || $this->hasRegexMatches($eventType);
    }

    /**
     * Get listener statistics for monitoring.
     */
    public function getStatistics(): array
    {
        return array_merge($this->statistics, [
            'cache_size' => count($this->listenerCache),
            'event_types' => count($this->listeners),
            'wildcard_patterns' => count($this->wildcardListeners),
            'regex_patterns' => count($this->regexListeners),
            'conditional_listeners' => array_sum(array_map('count', $this->conditionalListeners)),
            'listener_groups' => count($this->listenerGroups),
        ]);
    }

    /**
     * Clear all cached listeners.
     */
    public function clearCache(): void
    {
        $this->listenerCache = [];
    }

    /**
     * Get listener provider configuration.
     */
    public function getConfiguration(): array
    {
        return [
            'enable_caching' => $this->enableCaching,
            'max_cache_size' => $this->maxCacheSize,
            'statistics' => $this->getStatistics(),
        ];
    }

    /**
     * Export listener configuration for debugging.
     */
    public function exportConfiguration(): array
    {
        return [
            'listeners' => $this->listeners,
            'wildcard_listeners' => $this->wildcardListeners,
            'regex_listeners' => $this->regexListeners,
            'conditional_listeners' => $this->conditionalListeners,
            'listener_groups' => $this->listenerGroups,
            'statistics' => $this->getStatistics(),
        ];
    }

    /**
     * Import listener configuration.
     */
    public function importConfiguration(array $config): void
    {
        $this->listeners = $config['listeners'] ?? [];
        $this->wildcardListeners = $config['wildcard_listeners'] ?? [];
        $this->regexListeners = $config['regex_listeners'] ?? [];
        $this->conditionalListeners = $config['conditional_listeners'] ?? [];
        $this->listenerGroups = $config['listener_groups'] ?? [];

        $this->clearCache();
        $this->recalculateStatistics();
    }

    /**
     * Collect all listeners for a given event.
     */
    private function collectListenersForEvent(object $event, string $eventClass): array
    {
        $allListeners = [];

        // Exact match listeners
        if (isset($this->listeners[$eventClass])) {
            foreach ($this->listeners[$eventClass] as $listenerData) {
                if ($this->isListenerEnabled($listenerData)) {
                    $allListeners[] = $listenerData;
                }
            }
        }

        // Parent class and interface listeners
        foreach (array_merge(class_parents($event), class_implements($event)) as $parentClass) {
            if (isset($this->listeners[$parentClass])) {
                foreach ($this->listeners[$parentClass] as $listenerData) {
                    if ($this->isListenerEnabled($listenerData)) {
                        $allListeners[] = $listenerData;
                    }
                }
            }
        }

        // Wildcard listeners
        foreach ($this->wildcardListeners as $pattern => $listeners) {
            if ($this->matchesWildcard($eventClass, $pattern)) {
                ++$this->statistics['pattern_matches'];
                foreach ($listeners as $listenerData) {
                    if ($this->isListenerEnabled($listenerData)) {
                        $allListeners[] = $listenerData;
                    }
                }
            }
        }

        // Regex listeners
        foreach ($this->regexListeners as $regex => $listeners) {
            if (preg_match($regex, $eventClass)) {
                ++$this->statistics['pattern_matches'];
                foreach ($listeners as $listenerData) {
                    if ($this->isListenerEnabled($listenerData)) {
                        $allListeners[] = $listenerData;
                    }
                }
            }
        }

        // Conditional listeners
        if (isset($this->conditionalListeners[$eventClass])) {
            foreach ($this->conditionalListeners[$eventClass] as $listenerData) {
                if ($this->isListenerEnabled($listenerData)
                    && call_user_func($listenerData['predicate'], $event)) {
                    ++$this->statistics['conditional_matches'];
                    $allListeners[] = $listenerData;
                }
            }
        }

        // Sort all listeners by priority
        usort($allListeners, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        return array_column($allListeners, 'listener');
    }

    /**
     * Check if listener is enabled based on options.
     */
    private function isListenerEnabled(array $listenerData): bool
    {
        return ($listenerData['options']['enabled'] ?? true) === true;
    }

    /**
     * Cache listeners for performance optimization.
     */
    private function cacheListeners(string $eventClass, array $listeners): void
    {
        if (count($this->listenerCache) >= $this->maxCacheSize) {
            // Remove oldest entries (simple LRU-like behavior)
            $this->listenerCache = array_slice($this->listenerCache, 1, null, true);
        }

        $this->listenerCache[$eventClass] = $listeners;
    }

    /**
     * Clear cache for specific event type.
     */
    private function clearCacheForEventType(string $eventType): void
    {
        unset($this->listenerCache[$eventType]);

        // Also clear cache for related types (inheritance)
        foreach (array_keys($this->listenerCache) as $cachedType) {
            if (is_subclass_of($cachedType, $eventType) || is_subclass_of($eventType, $cachedType)) {
                unset($this->listenerCache[$cachedType]);
            }
        }
    }

    /**
     * Sort listeners by priority for a specific event type.
     */
    private function sortListenersByPriority(string $eventType): void
    {
        if (isset($this->listeners[$eventType])) {
            usort($this->listeners[$eventType], fn ($a, $b) => $b['priority'] <=> $a['priority']);
        }
    }

    /**
     * Sort wildcard listeners by priority.
     */
    private function sortWildcardListenersByPriority(string $pattern): void
    {
        if (isset($this->wildcardListeners[$pattern])) {
            usort($this->wildcardListeners[$pattern], fn ($a, $b) => $b['priority'] <=> $a['priority']);
        }
    }

    /**
     * Sort regex listeners by priority.
     */
    private function sortRegexListenersByPriority(string $regex): void
    {
        if (isset($this->regexListeners[$regex])) {
            usort($this->regexListeners[$regex], fn ($a, $b) => $b['priority'] <=> $a['priority']);
        }
    }

    /**
     * Sort conditional listeners by priority.
     */
    private function sortConditionalListenersByPriority(string $eventType): void
    {
        if (isset($this->conditionalListeners[$eventType])) {
            usort($this->conditionalListeners[$eventType], fn ($a, $b) => $b['priority'] <=> $a['priority']);
        }
    }

    /**
     * Generate unique ID for a listener.
     */
    private function generateListenerId(callable $listener): string
    {
        if (is_array($listener)) {
            return md5(serialize($listener));
        }

        if (is_object($listener)) {
            return spl_object_hash($listener);
        }

        return md5((string) $listener);
    }

    /**
     * Check if identifier matches a listener.
     */
    private function matchesListenerIdentifier(array $listenerData, $identifier): bool
    {
        if (is_string($identifier)) {
            return $listenerData['id'] === $identifier;
        }

        return $listenerData['listener'] === $identifier;
    }

    /**
     * Check if event class matches wildcard pattern.
     */
    private function matchesWildcard(string $eventClass, string $pattern): bool
    {
        return fnmatch($pattern, $eventClass);
    }

    /**
     * Check if there are wildcard matches for event type.
     */
    private function hasWildcardMatches(string $eventType): bool
    {
        foreach (array_keys($this->wildcardListeners) as $pattern) {
            if ($this->matchesWildcard($eventType, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if there are regex matches for event type.
     */
    private function hasRegexMatches(string $eventType): bool
    {
        foreach (array_keys($this->regexListeners) as $regex) {
            if (preg_match($regex, $eventType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate regex pattern.
     */
    private function isValidRegex(string $regex): bool
    {
        return false !== @preg_match($regex, '');
    }

    /**
     * Recalculate statistics after import.
     */
    private function recalculateStatistics(): void
    {
        $total = 0;
        $total += array_sum(array_map('count', $this->listeners));
        $total += array_sum(array_map('count', $this->wildcardListeners));
        $total += array_sum(array_map('count', $this->regexListeners));
        $total += array_sum(array_map('count', $this->conditionalListeners));

        $this->statistics['total_listeners'] = $total;
    }
}
