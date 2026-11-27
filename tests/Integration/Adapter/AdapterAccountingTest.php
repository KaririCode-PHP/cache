<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Integration\Adapter;

use KaririCode\Cache\Adapter\ApcuAdapter;
use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Adapter\FilesystemAdapter;
use KaririCode\Cache\Adapter\RedisAdapter;
use KaririCode\Cache\Configuration\RedisConnection;
use KaririCode\Cache\Contract\CacheAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every adapter counts the same way (hits, misses, writes, and deletes of
 * entries that existed), applies the TTL rules of ADR-010 at its own level,
 * and reports the backend size.
 */
final class AdapterAccountingTest extends TestCase
{
    private static ?string $directory = null;

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$directory) {
            (new FilesystemAdapter(self::$directory))->flush();
        }
    }

    private static function directory(): string
    {
        return self::$directory ??= sys_get_temp_dir() . '/kc-accounting-' . getmypid();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adapters(): iterable
    {
        yield 'array' => ['array'];
        yield 'apcu' => ['apcu'];
        yield 'filesystem' => ['filesystem'];
        yield 'redis' => ['redis'];
    }

    private function fresh(string $name): CacheAdapter
    {
        $adapter = match ($name) {
            'array' => new ArrayAdapter(),
            'apcu' => new ApcuAdapter(prefix: 'acct:'),
            'filesystem' => new FilesystemAdapter(self::directory()),
            'redis' => new RedisAdapter(new RedisConnection(host: (string) (getenv('KARIRICODE_CACHE_REDIS_HOST') ?: '127.0.0.1'), port: (int) (getenv('KARIRICODE_CACHE_REDIS_PORT') ?: 6379), database: 15), 'acct:'),
            default => throw new \LogicException($name),
        };

        if (!$adapter->isAvailable()) {
            self::markTestSkipped("{$name} backend not available");
        }

        self::assertTrue($adapter->flush());

        // counters are per instance: start from a clean one
        return match ($name) {
            'array' => $adapter,
            default => $this->reopen($name),
        };
    }

    private function reopen(string $name): CacheAdapter
    {
        return match ($name) {
            'apcu' => new ApcuAdapter(prefix: 'acct:'),
            'filesystem' => new FilesystemAdapter(self::directory()),
            'redis' => new RedisAdapter(new RedisConnection(host: (string) (getenv('KARIRICODE_CACHE_REDIS_HOST') ?: '127.0.0.1'), port: (int) (getenv('KARIRICODE_CACHE_REDIS_PORT') ?: 6379), database: 15), 'acct:'),
            default => throw new \LogicException($name),
        };
    }

    #[DataProvider('adapters')]
    public function testCountersFollowEveryOperation(string $name): void
    {
        $adapter = $this->fresh($name);

        $adapter->write('a', 1);
        $adapter->write('b', 2);
        $adapter->writeMultiple(['c' => 3, 'd' => 4]);
        $adapter->read('a');
        $adapter->read('zz');
        $adapter->readMultiple(['c', 'zz2']);
        $adapter->remove('a');
        $adapter->remove('zz');
        $adapter->removeMultiple(['c', 'zz3']);

        $stats = $adapter->stats();
        self::assertSame(
            ['hits' => 2, 'misses' => 2, 'writes' => 4, 'deletes' => 2],
            ['hits' => $stats->hits, 'misses' => $stats->misses, 'writes' => $stats->writes, 'deletes' => $stats->deletes],
        );
        self::assertSame($name, $stats->adapterName);
    }

    #[DataProvider('adapters')]
    public function testZeroAndNegativeTtlDeleteAtAdapterLevel(string $name): void
    {
        $adapter = $this->fresh($name);

        foreach ([0, -1] as $ttl) {
            self::assertTrue($adapter->write('k', 'old'));
            self::assertTrue($adapter->exists('k'));
            self::assertFalse($adapter->exists('never-written'));
            self::assertTrue($adapter->write('k', 'new', $ttl), "ttl {$ttl}");
            self::assertFalse($adapter->exists('k'), "ttl {$ttl} must delete");

            $adapter->write('m', 'old');
            self::assertTrue($adapter->writeMultiple(['m' => 'new'], $ttl), "writeMultiple ttl {$ttl}");
            self::assertFalse($adapter->exists('m'), "writeMultiple ttl {$ttl} must delete");
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adaptersCountingFlushes(): iterable
    {
        yield 'filesystem' => ['filesystem'];
        yield 'redis' => ['redis'];
    }

    #[DataProvider('adaptersCountingFlushes')]
    public function testFlushCountsTheEntriesItRemoves(string $name): void
    {
        $adapter = $this->fresh($name);
        $adapter->writeMultiple(['x' => 1, 'y' => 2, 'z' => 3]);

        self::assertTrue($adapter->flush());
        self::assertSame(3, $adapter->stats()->deletes);
        self::assertFalse($adapter->exists('x'));
    }

    public function testApcuFlushTreatsThePrefixLiterally(): void
    {
        $dotted = new ApcuAdapter(prefix: 'p.q:');
        $lookalike = new ApcuAdapter(prefix: 'pXq:');

        if (!$dotted->isAvailable()) {
            self::markTestSkipped('APCu not available');
        }

        $lookalike->write('k', 1);
        $dotted->write('k', 2);

        self::assertTrue($dotted->flush());
        self::assertFalse($dotted->exists('k'));
        self::assertTrue($lookalike->exists('k'), 'the "." in the prefix is not a regex wildcard');
        $lookalike->flush();
    }

    public function testRedisBatchWritesApplyTheTtl(): void
    {
        $adapter = $this->fresh('redis');
        self::assertInstanceOf(RedisAdapter::class, $adapter);

        $adapter->writeMultiple(['t' => 1], 60);
        $adapter->writeMultiple(['p' => 1]);
        $ttl = $adapter->connection()->ttl('acct:t');

        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(60, $ttl);
        self::assertSame(-1, $adapter->connection()->ttl('acct:p'));
    }

    public function testApcuReportsTheSegmentSize(): void
    {
        $adapter = new ApcuAdapter();

        if (!$adapter->isAvailable()) {
            self::markTestSkipped('APCu not available');
        }

        $adapter->flush();
        $adapter->writeMultiple(['x' => str_repeat('a', 100), 'y' => 2, 'z' => 3]);
        $stats = $adapter->stats();

        self::assertSame(3, $stats->itemCount);
        self::assertGreaterThan(0, $stats->memoryBytes);
        $adapter->flush();
    }

    public function testFilesystemReportsItsFilesAndBytes(): void
    {
        $adapter = $this->fresh('filesystem');
        $adapter->writeMultiple(['x' => str_repeat('a', 100), 'y' => 2, 'z' => 3]);

        $bytes = 0;
        $files = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::directory(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $bytes += $file->getSize();
                ++$files;
            }
        }

        $stats = $adapter->stats();
        self::assertSame(3, $files);
        self::assertSame(3, $stats->itemCount);
        self::assertSame($bytes, $stats->memoryBytes);
    }
}
