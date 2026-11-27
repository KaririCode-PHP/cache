<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Integration;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Adapter\NullAdapter;
use KaririCode\Cache\Bridge\Psr16Bridge;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Eviction\SievePolicy;
use KaririCode\Cache\Layered\LayeredCache;
use KaririCode\Cache\Registry\CacheRegistry;
use KaririCode\Cache\Stampede\XFetchPrevention;
use KaririCode\Cache\Tag\TagAwareCache;
use PHPUnit\Framework\TestCase;

/**
 * Full integration tests exercising the entire cache pipeline:
 * Cache + SIEVE + XFetch + TagAware + Layered + Manager + PSR-16 Bridge.
 */
final class FullPipelineTest extends TestCase
{
    public function testCacheWithSieveEviction(): void
    {
        $cache = new Cache(
            adapter: new ArrayAdapter(maxItems: 200),
            evictionPolicy: new SievePolicy(capacity: 3),
        );

        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->set('c', 3);

        // Access 'a' → sets visited bit
        $cache->get('a');

        // Insert 'd' → triggers SIEVE eviction
        $cache->set('d', 4);

        // 'a' was visited → should survive; one of b/c evicted
        self::assertSame(1, $cache->get('a'));
        self::assertSame(4, $cache->get('d'));
    }

    public function testRememberWithXFetch(): void
    {
        $cache = new Cache(
            adapter: new ArrayAdapter(maxItems: 100),
            stampedePrevention: new XFetchPrevention(beta: 1.0),
        );

        $callCount = 0;

        $factory = function () use (&$callCount): string {
            ++$callCount;

            return 'expensive_result';
        };

        $result = $cache->remember('computation', $factory, 3600);

        self::assertSame('expensive_result', $result);
        self::assertSame(1, $callCount);

        // Second call should return cached value
        $result2 = $cache->remember('computation', $factory, 3600);

        self::assertSame('expensive_result', $result2);
        self::assertSame(1, $callCount);
    }

    public function testTagAwareCacheInvalidation(): void
    {
        $tagCache = new TagAwareCache(new ArrayAdapter());

        $tagCache->setTagged('user.42.profile', ['name' => 'Walmir'], ['users', 'user.42']);
        $tagCache->setTagged('user.42.posts', ['post1', 'post2'], ['users', 'user.42']);
        $tagCache->setTagged('user.99.profile', ['name' => 'Other'], ['users', 'user.99']);

        // Invalidate user.42 tag
        $tagCache->invalidateTags(['user.42']);

        // user.42 entries should miss
        self::assertNull($tagCache->get('user.42.profile'));
        self::assertNull($tagCache->get('user.42.posts'));

        // user.99 should survive
        self::assertSame(['name' => 'Other'], $tagCache->get('user.99.profile'));
    }

    public function testLayeredCacheWithPromotion(): void
    {
        $l1 = new ArrayAdapter(maxItems: 100);
        $l2 = new ArrayAdapter(maxItems: 1000);

        $layered = new LayeredCache();
        $layered->addLayer($l1, 30);
        $layered->addLayer($l2, 300);

        // Write to all layers
        $layered->set('key', 'value');

        // Both layers should have it
        self::assertSame('value', self::unwrap($l1->read('key')));
        self::assertSame('value', self::unwrap($l2->read('key')));

        // Simulate L1 miss by removing from L1
        $l1->remove('key');
        self::assertNull(self::unwrap($l1->read('key')));

        // Get should find in L2 and promote to L1
        self::assertSame('value', $layered->get('key'));
        self::assertSame('value', self::unwrap($l1->read('key')));
    }

    public function testRegistryWithSeveralStores(): void
    {
        $registry = new CacheRegistry(defaultStore: 'primary');

        $primaryCache = new Cache(new ArrayAdapter(maxItems: 100));
        $secondaryCache = new Cache(new ArrayAdapter(maxItems: 100));

        $registry->register('primary', $primaryCache);
        $registry->register('secondary', $secondaryCache);

        // Default store operations
        $registry->store()->set('key', 'primary_value');
        self::assertSame('primary_value', $registry->store()->get('key'));

        // Named store operations
        $registry->store('secondary')->set('key', 'secondary_value');
        self::assertSame('secondary_value', $registry->store('secondary')->get('key'));

        // Stores are independent
        self::assertSame('primary_value', $registry->store()->get('key'));
    }

    public function testPsr16BridgeIntegration(): void
    {
        $cache = new Cache(new ArrayAdapter(maxItems: 100));
        $bridge = new Psr16Bridge($cache);

        $bridge->set('psr16.key', 'psr16_value');

        self::assertSame('psr16_value', $bridge->get('psr16.key'));
        self::assertTrue($bridge->has('psr16.key'));

        // Verify unwrap returns original cache
        self::assertSame('psr16_value', $bridge->unwrap()->get('psr16.key'));
    }

    public function testEndToEndFullStack(): void
    {
        // Build a full production-like stack
        $l1 = new ArrayAdapter(maxItems: 50);
        $l2 = new ArrayAdapter(maxItems: 500);

        $layered = new LayeredCache();
        $layered->addLayer($l1, 30);
        $layered->addLayer($l2, 300);

        $registry = new CacheRegistry(defaultStore: 'main');
        $registry->register('main', $layered);
        $registry->register('null', new Cache(new NullAdapter()));

        // Write and read through the full stack
        $registry->store()->set('app.config', ['debug' => false]);
        self::assertSame(['debug' => false], $registry->store()->get('app.config'));

        // Null store always misses
        self::assertNull($registry->store('null')->get('app.config'));

        // Clear all stores
        $registry->clearAll();
        self::assertNull($registry->store()->get('app.config'));
    }

    private static function unwrap(mixed $stored): mixed
    {
        return $stored instanceof \KaririCode\Cache\ValueObject\CacheEntry ? $stored->value : $stored;
    }
}
