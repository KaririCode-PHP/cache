<?php

declare(strict_types=1);

namespace KaririCode\Cache\Registry;

use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Exception\StoreNotFoundException;

/**
 * Registry of named caches with a default store.
 *
 * Replaces 4.0's CacheManager: "Manager" is a banned vague name (Spec V4
 * §18), and delegating the whole Cache contract to the default store gave
 * one class two responsibilities and 17 public methods. The registry only
 * resolves stores; callers use the Cache they get back.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): stores are any Cache implementation.
 * Spec V4: Entity (mutable registry; fluent register()).
 *
 * Preconditions: names are non-empty; register the default store before calling store() without a name.
 * Thread-safety: mutable registry: register at boot, then share.
 *
 * Side effects: clearAll() clears every registered store.
 *
 * Example:
 *     $registry->register('sessions', new Cache(new RedisAdapter(prefix: 'sess:')))->store('sessions')->set('abc', $s, 1800);
 *
 * References: ADR-013; SPEC-001 §8.
 *
 * @see https://martinfowler.com/eaaCatalog/registry.html
 */
final class CacheRegistry
{
    /** @var array<string, Cache> */
    private array $stores = [];

    public function __construct(
        private readonly string $defaultStore = 'default',
    ) {
        if ('' === $defaultStore) {
            throw ConfigurationException::invalidOption(option: 'defaultStore', constraint: 'must not be empty');
        }
    }

    /**
     * Register (or replace) the store $name.
     */
    public function register(string $name, Cache $cache): self
    {
        if ('' === $name) {
            throw ConfigurationException::invalidOption(option: 'store name', constraint: 'must not be empty');
        }

        $this->stores[$name] = $cache;

        return $this;
    }

    /**
     * The store $name, or the default store when $name is null.
     *
     * @throws StoreNotFoundException 8620 when the store is not registered
     */
    public function store(?string $name = null): Cache
    {
        $name ??= $this->defaultStore;

        return $this->stores[$name] ?? throw StoreNotFoundException::storeNotRegistered();
    }

    public function has(string $name): bool
    {
        return isset($this->stores[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->stores));
    }

    /**
     * Clear every registered store; false when any of them failed.
     */
    public function clearAll(): bool
    {
        $success = true;

        foreach ($this->stores as $store) {
            $success = $store->clear() && $success;
        }

        return $success;
    }
}
