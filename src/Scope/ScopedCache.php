<?php

declare(strict_types=1);

namespace KaririCode\Cache\Scope;

use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Exception\InvalidKeyException;

/**
 * Namespace-scoped view of another cache: every key becomes
 * "{$scope}:{$key}" (tenant isolation over one backend).
 *
 * Scopes nest ("tenant:42:users:"). clear() delegates to the inner cache
 * and therefore clears every scope that shares it; deleting one scope needs
 * its own adapter prefix or tags (ADR-006).
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): decorates any Cache.
 * Spec V4: Value-like decorator (final readonly class).
 *
 * Preconditions: the scope is non-empty and has no reserved characters.
 * Thread-safety: stateless decorator; as safe as the inner cache.
 *
 * Side effects: delegates to the inner cache; clear() clears the inner cache.
 *
 * Example:
 *     $tenant = $cache->withScope('tenant:42');
 *
 * References: ADR-006.
 *
 * @see https://learn.microsoft.com/en-us/azure/architecture/guide/multitenant/approaches/storage-data#shared-multitenant-databases-and-file-stores
 */
final readonly class ScopedCache implements Cache
{
    public function __construct(
        private Cache $inner,
        private string $scope,
    ) {
        if ('' === $scope) {
            throw ConfigurationException::invalidOption(option: 'scope', constraint: 'must not be empty');
        }

        try {
            InvalidKeyException::validate($scope);
        } catch (InvalidKeyException) {
            throw ConfigurationException::invalidOption(option: 'scope', constraint: 'must not contain {}()/\@');
        }
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($this->scoped($key), $default);
    }

    #[\Override]
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->inner->set(key: $this->scoped($key), value: $value, ttl: $ttl);
    }

    #[\Override]
    public function delete(string $key): bool
    {
        return $this->inner->delete($this->scoped($key));
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->inner->has($this->scoped($key));
    }

    #[\Override]
    public function clear(): bool
    {
        return $this->inner->clear();
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->inner->get($this->scoped($key), $default);
        }

        return $results;
    }

    #[\Override]
    public function setMultiple(iterable $values, ?int $ttl = null): bool
    {
        $scoped = [];

        foreach ($values as $key => $value) {
            $scoped[$this->scoped((string) $key)] = $value;
        }

        return $this->inner->setMultiple($scoped, $ttl);
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $scoped = [];

        foreach ($keys as $key) {
            $scoped[] = $this->scoped($key);
        }

        return $this->inner->deleteMultiple($scoped);
    }

    #[\Override]
    public function remember(string $key, callable $factory, ?int $ttl = null, array $tags = []): mixed
    {
        return $this->inner->remember(key: $this->scoped($key), factory: $factory, ttl: $ttl, tags: $tags);
    }

    #[\Override]
    public function rememberStale(string $key, callable $factory, int $freshTtl, int $staleTtl): mixed
    {
        return $this->inner->rememberStale(key: $this->scoped($key), factory: $factory, freshTtl: $freshTtl, staleTtl: $staleTtl);
    }

    #[\Override]
    public function withScope(string $scope): Cache
    {
        return new self($this->inner, $this->scope . ':' . $scope);
    }

    private function scoped(string $key): string
    {
        InvalidKeyException::validate($key);

        return $this->scope . ':' . $key;
    }
}
