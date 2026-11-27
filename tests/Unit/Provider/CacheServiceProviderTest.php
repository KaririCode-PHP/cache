<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Provider;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Bridge\Psr16Bridge;
use KaririCode\Cache\Bridge\Psr6Bridge;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Provider\CacheServiceProvider;
use KaririCode\Cache\Registry\CacheRegistry;
use KaririCode\Cache\Tests\Fixtures\RecordingContainer;
use KaririCode\Contract\DI\ServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;

final class CacheServiceProviderTest extends TestCase
{
    public function testRegistersLazilyAndBuildsTheRegistryOnce(): void
    {
        $built = 0;
        $default = new Cache(new ArrayAdapter());
        $provider = new CacheServiceProvider(static function () use (&$built, $default): CacheRegistry {
            ++$built;

            return (new CacheRegistry())->register('default', $default);
        });
        $container = new RecordingContainer();

        $provider->register($container);
        $provider->boot();

        self::assertInstanceOf(ServiceProvider::class, $provider);
        self::assertSame(0, $built, 'register() only binds');
        self::assertSame([CacheRegistry::class, CacheContract::class, CacheInterface::class, CacheItemPoolInterface::class], array_keys($container->bindings));
        self::assertSame([true, true, true, true], array_column($container->bindings, 'singleton'));

        self::assertSame($default, $container->get(CacheContract::class));
        self::assertSame($container->get(CacheRegistry::class), $container->get(CacheRegistry::class));
        self::assertSame(1, $built);
    }

    public function testPsrBindingsWrapTheDefaultStore(): void
    {
        $default = new Cache(new ArrayAdapter());
        $container = new RecordingContainer();
        (new CacheServiceProvider(static fn(): CacheRegistry => (new CacheRegistry())->register('default', $default)))->register($container);

        $simple = $container->get(CacheInterface::class);
        $pool = $container->get(CacheItemPoolInterface::class);

        self::assertInstanceOf(Psr16Bridge::class, $simple);
        self::assertInstanceOf(Psr6Bridge::class, $pool);
        $simple->set('k', 'v');
        self::assertSame('v', $default->get('k'));
        self::assertTrue($pool->getItem('k')->isHit());
    }
}
