<?php

declare(strict_types=1);

namespace KaririCode\Cache\Provider;

use KaririCode\Cache\Bridge\Psr16Bridge;
use KaririCode\Cache\Bridge\Psr6Bridge;
use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Registry\CacheRegistry;
use KaririCode\Contract\DI\BindableContainer;
use KaririCode\Contract\DI\ServiceProvider;

/**
 * Registers the package in a kariricode/contract container: the
 * CacheRegistry (built lazily by the given factory), its default store as
 * Cache, and — when the PSR packages are installed — the same store as
 * PSR-16 CacheInterface and PSR-6 CacheItemPoolInterface. Components type
 * against the PSR interfaces (Spec V4 §31) and receive this cache.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): consumers see PSR or contract types only.
 * Spec V4: stateless service (final readonly class); contract 2.11 DI family.
 *
 * Preconditions: the factory returns the registry with a default store.
 * Thread-safety: stateless; bindings are singletons owned by the container.
 *
 * Side effects: register() binds four ids; nothing is built until the
 * container resolves one of them. boot() does nothing.
 *
 * Example:
 *     $container->register(new CacheServiceProvider(static fn (): CacheRegistry => (new CacheRegistry())->register('default', $cache)));
 *
 * References: ADR-013; SPEC-001 §8.
 *
 * @see https://www.php-fig.org/psr/psr-16/
 */
final readonly class CacheServiceProvider implements ServiceProvider
{
    /**
     * @param \Closure(): CacheRegistry $registry Runs once, on first resolution.
     */
    public function __construct(
        private \Closure $registry,
    ) {}

    #[\Override]
    public function register(BindableContainer $container): void
    {
        $container->bind(abstract: CacheRegistry::class, concrete: $this->registry, singleton: true);
        $container->bind(abstract: Cache::class, concrete: static fn(): Cache => self::registryFrom($container)->store(), singleton: true);

        if (interface_exists(\Psr\SimpleCache\CacheInterface::class)) {
            $container->bind(
                abstract: \Psr\SimpleCache\CacheInterface::class,
                concrete: static fn(): Psr16Bridge => new Psr16Bridge(self::registryFrom($container)->store()),
                singleton: true,
            );
        }

        if (interface_exists(\Psr\Cache\CacheItemPoolInterface::class)) {
            $container->bind(
                abstract: \Psr\Cache\CacheItemPoolInterface::class,
                concrete: static fn(): Psr6Bridge => new Psr6Bridge(self::registryFrom($container)->store()),
                singleton: true,
            );
        }
    }

    #[\Override]
    public function boot(): void {}

    private static function registryFrom(BindableContainer $container): CacheRegistry
    {
        $registry = $container->get(CacheRegistry::class);

        if (!$registry instanceof CacheRegistry) {
            throw ConfigurationException::invalidOption(option: CacheRegistry::class, constraint: 'the container must resolve it to a CacheRegistry');
        }

        return $registry;
    }
}
