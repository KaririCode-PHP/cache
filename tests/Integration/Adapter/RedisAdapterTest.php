<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Integration\Adapter;

use KaririCode\Cache\Adapter\RedisAdapter;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Compression\ZlibCompressor;
use KaririCode\Cache\Configuration\RedisConnection;
use KaririCode\Cache\Exception\BackendException;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\ConnectionException;
use KaririCode\Cache\Serialization\PayloadCodec;
use KaririCode\Cache\Serialization\PhpSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real Redis (KARIRICODE_CACHE_REDIS_HOST/PORT); skipped
 * when the extension or the server is unavailable.
 */
final class RedisAdapterTest extends TestCase
{
    private \Redis $raw;
    private string $host;
    private int $port;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis not loaded');
        }

        $this->host = (string) (getenv('KARIRICODE_CACHE_REDIS_HOST') ?: '127.0.0.1');
        $this->port = (int) (getenv('KARIRICODE_CACHE_REDIS_PORT') ?: 6379);
        $this->raw = new \Redis();

        try {
            $this->raw->connect($this->host, $this->port, 1.0);
            $this->raw->select(15);
            $this->raw->flushDB();
        } catch (\RedisException) {
            self::markTestSkipped("no Redis server at {$this->host}:{$this->port}");
        }
    }

    public function testReadWriteExistsRemove(): void
    {
        $adapter = $this->adapter('t1:');

        self::assertTrue($adapter->write('k', ['a' => 1]));
        self::assertSame(['a' => 1], $adapter->read('k'));
        self::assertTrue($adapter->exists('k'));
        self::assertTrue($adapter->remove('k'));
        self::assertTrue($adapter->remove('k'), 'idempotent');
        self::assertNull($adapter->read('k'));
    }

    public function testNonPositiveTtlDeletesInsteadOfFailing(): void
    {
        $adapter = $this->adapter('t2:');
        $adapter->write('k', 'v');

        self::assertTrue($adapter->write('k', 'v', 0));
        self::assertFalse($adapter->exists('k'));
        self::assertTrue($adapter->writeMultiple(['a' => 1, 'b' => 2], -1));
    }

    public function testFlushWithPrefixRemovesOnlyThatPrefixEvenAmongManyKeys(): void
    {
        $pipeline = $this->raw->pipeline();
        for ($i = 0; $i < 20_000; ++$i) {
            $pipeline->set("other:{$i}", '1');
        }
        $pipeline->exec();

        $adapter = $this->adapter('mine:');
        for ($i = 0; $i < 30; ++$i) {
            $adapter->write("k{$i}", $i);
        }

        self::assertTrue($adapter->flush());
        self::assertSame([], $this->raw->keys('mine:*'));
        self::assertCount(20_000, $this->raw->keys('other:*'));
    }

    public function testGlobCharactersInThePrefixAreEscaped(): void
    {
        $this->raw->set('a1:k', '1');
        $adapter = $this->adapter('a?:');
        $adapter->write('k', 'v');

        $adapter->flush();

        self::assertSame('1', $this->raw->get('a1:k'), '"?" in the prefix must not match "1"');
    }

    public function testBatchOperations(): void
    {
        $adapter = $this->adapter('t3:');

        self::assertTrue($adapter->writeMultiple(['a' => 1, 'b' => 2], 60));
        self::assertSame(['a' => 1, 'b' => 2, 'c' => null], $adapter->readMultiple(['a', 'b', 'c']));
        self::assertTrue($adapter->removeMultiple(['a', 'b']));
        self::assertSame(['a' => null], $adapter->readMultiple(['a']));
    }

    #[Group('security')]
    public function testUndecodablePayloadIsAMissAndIsDeleted(): void
    {
        $adapter = $this->adapter('t4:');
        $this->raw->set('t4:bad', 'not a serialized payload');

        self::assertNull($adapter->read('bad'));
        self::assertSame(0, $this->raw->exists('t4:bad'));
    }

    public function testCompressionRoundTripThroughCache(): void
    {
        $adapter = $this->adapter('t5:', new ZlibCompressor(threshold: 16));
        $cache = new Cache($adapter);
        $payload = str_repeat('abc', 1000);

        $cache->set('big', $payload, 60);

        self::assertSame($payload, $cache->get('big'));
        self::assertLessThan(strlen($payload), strlen((string) $this->raw->get('t5:big')));
    }

    #[Group('security')]
    public function testRestrictiveAllowlistStillReadsThePackageEnvelopes(): void
    {
        $cache = new Cache(new RedisAdapter(new RedisConnection($this->host, $this->port, 15), 'allow:', new PayloadCodec(new PhpSerializer([]))));
        $cache->set('scalar', 42, 60);

        self::assertSame(42, $cache->get('scalar'));
    }

    #[Group('security')]
    public function testBackendFailureDuringAnOperationIsWrapped(): void
    {
        $adapter = new RedisAdapter(new RedisConnection($this->host, $this->port, 15, readTimeout: 0.1), 'pause:');
        $admin = new \Redis();
        $admin->connect($this->host, $this->port);
        // Every client blocks for 300 ms; the adapter gives up after 100 ms.
        $admin->rawCommand('CLIENT', 'PAUSE', '300', 'ALL');

        try {
            $adapter->read('k');
            self::fail('a read timeout must surface as CacheException');
        } catch (CacheException $e) {
            self::assertSame(BackendException::READ_FAILED, $e->getCode());
            self::assertInstanceOf(\RedisException::class, $e->getPrevious());
        } finally {
            usleep(350_000);
            $admin->close();
        }
    }

    /**
     * @return iterable<string, array{\Closure(RedisAdapter): mixed, int}>
     */
    public static function operationsUnderAnUnresponsiveServer(): iterable
    {
        yield 'write' => [static fn(RedisAdapter $a): mixed => $a->write('k', 'v', 60), BackendException::WRITE_FAILED];
        yield 'remove' => [static fn(RedisAdapter $a): mixed => $a->remove('k'), BackendException::DELETE_FAILED];
        yield 'exists' => [static fn(RedisAdapter $a): mixed => $a->exists('k'), BackendException::READ_FAILED];
        yield 'flush' => [static fn(RedisAdapter $a): mixed => $a->flush(), BackendException::FLUSH_FAILED];
        yield 'readMultiple' => [static fn(RedisAdapter $a): mixed => $a->readMultiple(['a', 'b']), BackendException::READ_FAILED];
        yield 'writeMultiple' => [static fn(RedisAdapter $a): mixed => $a->writeMultiple(['a' => 1], 60), BackendException::WRITE_FAILED];
        yield 'removeMultiple' => [static fn(RedisAdapter $a): mixed => $a->removeMultiple(['a']), BackendException::DELETE_FAILED];
        yield 'stats' => [static fn(RedisAdapter $a): mixed => $a->stats(), BackendException::READ_FAILED];
    }

    /**
     * @param \Closure(RedisAdapter): mixed $operation
     */
    #[Group('security')]
    #[DataProvider('operationsUnderAnUnresponsiveServer')]
    public function testEveryOperationWrapsBackendFailures(\Closure $operation, int $expectedCode): void
    {
        $adapter = new RedisAdapter(new RedisConnection($this->host, $this->port, 15, readTimeout: 0.1), 'pause:');
        $admin = new \Redis();
        $admin->connect($this->host, $this->port);
        $admin->rawCommand('CLIENT', 'PAUSE', '300', 'ALL');

        try {
            $operation($adapter);
            self::fail('an unresponsive server must surface as CacheException');
        } catch (CacheException $e) {
            self::assertSame($expectedCode, $e->getCode());
            self::assertInstanceOf(\RedisException::class, $e->getPrevious());
        } finally {
            usleep(350_000);
            $admin->close();
        }
    }

    public function testIsAvailableAndConnectionFailure(): void
    {
        self::assertTrue($this->adapter('t6:')->isAvailable());

        $this->expectException(ConnectionException::class);
        $this->expectExceptionCode(ConnectionException::CONNECTION_FAILED);
        new RedisAdapter(new RedisConnection(port: 1, timeout: 0.2));
    }

    private function adapter(string $prefix, ?ZlibCompressor $compressor = null): RedisAdapter
    {
        return $compressor === null
            ? new RedisAdapter(new RedisConnection($this->host, $this->port, 15), $prefix, new PayloadCodec(new PhpSerializer()))
            : new RedisAdapter(new RedisConnection($this->host, $this->port, 15), $prefix, new PayloadCodec(compressor: $compressor));
    }
}
