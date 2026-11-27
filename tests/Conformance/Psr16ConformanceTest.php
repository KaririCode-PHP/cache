<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Conformance;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Adapter\NullAdapter;
use KaririCode\Cache\Bridge\Psr16Bridge;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Contract\CacheAdapter;
use KaririCode\Cache\Exception\BackendException;
use KaririCode\Cache\Layered\LayeredCache;
use KaririCode\Cache\Tag\TagAwareCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheException as PsrCacheException;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * PSR-16 clauses, checked against every Cache implementation of the package.
 *
 * @see https://www.php-fig.org/psr/psr-16/
 */
#[Group('security')]
final class Psr16ConformanceTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): CacheInterface}>
     */
    public static function pools(): iterable
    {
        yield 'Cache' => [static fn(): CacheInterface => new Psr16Bridge(new Cache(new ArrayAdapter()))];
        yield 'LayeredCache' => [static fn(): CacheInterface => new Psr16Bridge((new LayeredCache())->addLayer(new ArrayAdapter())->addLayer(new ArrayAdapter()))];
        yield 'TagAwareCache' => [static fn(): CacheInterface => new Psr16Bridge(new TagAwareCache(new ArrayAdapter()))];
    }

    /**
     * @param \Closure(): CacheInterface $make
     */
    #[DataProvider('pools')]
    public function testSetGetHasDelete(\Closure $make): void
    {
        $cache = $make();

        self::assertTrue($cache->set('key', 'value'));
        self::assertSame('value', $cache->get('key'));
        self::assertTrue($cache->has('key'));
        self::assertTrue($cache->delete('key'));
        self::assertSame('default', $cache->get('key', 'default'));
        self::assertTrue($cache->delete('key'), '§ delete: missing key is not an error');
    }

    /**
     * @param \Closure(): CacheInterface $make
     */
    #[DataProvider('pools')]
    public function testCachedNullIsReturnedInsteadOfTheDefault(\Closure $make): void
    {
        $cache = $make();
        $cache->set('null', null);

        self::assertNull($cache->get('null', 'default'));
        self::assertTrue($cache->has('null'));
    }

    /**
     * @param \Closure(): CacheInterface $make
     */
    #[DataProvider('pools')]
    public function testZeroOrNegativeTtlDeletes(\Closure $make): void
    {
        $cache = $make();

        foreach ([0, -1, new \DateInterval('PT0S')] as $ttl) {
            $cache->set('k', 'v');
            self::assertTrue($cache->set('k', 'v', $ttl));
            self::assertFalse($cache->has('k'));
        }
    }

    /**
     * @param \Closure(): CacheInterface $make
     */
    #[DataProvider('pools')]
    public function testDateIntervalTtlIsHonoured(\Closure $make): void
    {
        $cache = $make();
        $cache->set('k', 'v', new \DateInterval('PT1S'));

        self::assertSame('v', $cache->get('k'));
        usleep(2_100_000);
        self::assertNull($cache->get('k'));
    }

    /**
     * @param \Closure(): CacheInterface $make
     */
    #[DataProvider('pools')]
    public function testMultipleOperations(\Closure $make): void
    {
        $cache = $make();

        self::assertTrue($cache->setMultiple(['a' => 1, 'b' => 2]));
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 'd'], iterator_to_array(self::iterable($cache->getMultiple(['a', 'b', 'c'], 'd'))));
        self::assertTrue($cache->deleteMultiple(['a', 'missing']));
        self::assertFalse($cache->has('a'));
        self::assertTrue($cache->setMultiple(new \ArrayIterator(['x' => 1])), 'Traversable accepted');
        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('b'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedKeys(): iterable
    {
        yield 'empty' => [''];

        foreach (str_split('{}()/\@:') as $char) {
            yield "reserved {$char}" => ["a{$char}b"];
        }
    }

    #[DataProvider('reservedKeys')]
    public function testIllegalKeysRaiseThePsrInvalidArgumentException(string $key): void
    {
        $cache = new Psr16Bridge(new Cache(new ArrayAdapter()));

        foreach ([
            static fn() => $cache->get($key),
            static fn() => $cache->set($key, 1),
            static fn() => $cache->has($key),
            static fn() => $cache->delete($key),
            static fn() => $cache->getMultiple([$key]),
            static fn() => $cache->setMultiple([$key => 1]),
            static fn() => $cache->deleteMultiple([$key]),
        ] as $call) {
            try {
                $call();
                self::fail("key '{$key}' accepted");
            } catch (InvalidArgumentException $e) {
                self::assertInstanceOf(PsrCacheException::class, $e);
            }
        }
    }

    public function testLegalKeysOfTheMinimumSetAreAccepted(): void
    {
        $cache = new Psr16Bridge(new Cache(new ArrayAdapter()));
        $key = str_repeat('Az09_.', 10) . 'abcd';     // 64 characters

        self::assertTrue($cache->set($key, 1));
        self::assertSame(1, $cache->get($key));
    }

    public function testBackendFailuresRaiseThePsrCacheException(): void
    {
        $failing = new class implements CacheAdapter {
            public function read(string $key): mixed
            {
                throw BackendException::readFailed();
            }

            public function write(string $key, mixed $value, ?int $ttl = null): bool
            {
                throw BackendException::writeFailed();
            }

            public function remove(string $key): bool
            {
                return true;
            }

            public function exists(string $key): bool
            {
                return false;
            }

            public function flush(): bool
            {
                return true;
            }

            public function readMultiple(array $keys): array
            {
                return [];
            }

            public function writeMultiple(array $values, ?int $ttl = null): bool
            {
                return true;
            }

            public function removeMultiple(array $keys): bool
            {
                return true;
            }

            public function stats(): \KaririCode\Cache\ValueObject\AdapterStats
            {
                return (new NullAdapter())->stats();
            }

            public function name(): string
            {
                return 'failing';
            }

            public function isAvailable(): bool
            {
                return false;
            }
        };

        $this->expectException(PsrCacheException::class);

        (new Psr16Bridge(new Cache($failing)))->get('k');
    }

    /**
     * @param iterable<mixed, mixed> $items
     *
     * @return \Iterator<mixed, mixed>
     */
    private static function iterable(iterable $items): \Iterator
    {
        return is_array($items) ? new \ArrayIterator($items) : (static fn() => yield from $items)();
    }
}
