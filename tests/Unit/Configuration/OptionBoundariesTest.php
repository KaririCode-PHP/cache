<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Configuration;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Compression\ZlibCompressor;
use KaririCode\Cache\Configuration\FilesystemOptions;
use KaririCode\Cache\Configuration\RedisConnection;
use KaririCode\Cache\Eviction\FifoPolicy;
use KaririCode\Cache\Eviction\LfuPolicy;
use KaririCode\Cache\Eviction\LruPolicy;
use KaririCode\Cache\Eviction\SievePolicy;
use KaririCode\Cache\Exception\BackendException;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Scope\ScopedCache;
use KaririCode\Cache\Stampede\NullPrevention;
use KaririCode\Cache\Stampede\XFetchPrevention;
use KaririCode\Cache\ValueObject\CacheEntry;
use KaririCode\Cache\ValueObject\TaggedEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Documented defaults, and the exact edges of every option range: the last
 * accepted value and the first rejected one.
 */
final class OptionBoundariesTest extends TestCase
{
    public function testFilesystemOptionDefaults(): void
    {
        $options = new FilesystemOptions();

        self::assertSame(
            [0o755, 0o644, 2, 1, 1000],
            [$options->directoryPermissions, $options->filePermissions, $options->shardingLevels, $options->gcProbability, $options->gcDivisor],
        );
    }

    public function testFilesystemOptionEdgesAreAccepted(): void
    {
        $low = new FilesystemOptions(directoryPermissions: 0, filePermissions: 0, shardingLevels: 0, gcProbability: 0, gcDivisor: 1);
        $high = new FilesystemOptions(directoryPermissions: 0o7777, filePermissions: 0o7777, shardingLevels: 8);

        self::assertSame([0, 0, 0, 1], [$low->directoryPermissions, $low->filePermissions, $low->shardingLevels, $low->gcDivisor]);
        self::assertSame([0o7777, 0o7777, 8], [$high->directoryPermissions, $high->filePermissions, $high->shardingLevels]);
    }

    public function testRedisConnectionDefaultsAndEdges(): void
    {
        $default = new RedisConnection();
        $low = new RedisConnection(port: 0, timeout: 0.0, readTimeout: 0.0);
        $high = new RedisConnection(port: 65535);

        self::assertSame(['127.0.0.1', 6379, 0, 2.0, 2.0], [$default->host, $default->port, $default->database, $default->timeout, $default->readTimeout]);
        self::assertSame([0, 0.0, 0.0, 65535], [$low->port, $low->timeout, $low->readTimeout, $high->port]);
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function valuesPastTheEdge(): iterable
    {
        yield 'directoryPermissions -1' => [static fn(): mixed => new FilesystemOptions(directoryPermissions: -1)];
        yield 'directoryPermissions 0o10000' => [static fn(): mixed => new FilesystemOptions(directoryPermissions: 0o10000)];
        yield 'filePermissions -1' => [static fn(): mixed => new FilesystemOptions(filePermissions: -1)];
        yield 'filePermissions 0o10000' => [static fn(): mixed => new FilesystemOptions(filePermissions: 0o10000)];
        yield 'shardingLevels -1' => [static fn(): mixed => new FilesystemOptions(shardingLevels: -1)];
        yield 'shardingLevels 9' => [static fn(): mixed => new FilesystemOptions(shardingLevels: 9)];
        yield 'gcDivisor 0' => [static fn(): mixed => new FilesystemOptions(gcProbability: 0, gcDivisor: 0)];
        yield 'host empty' => [static fn(): mixed => new RedisConnection(host: '')];
        yield 'port -1' => [static fn(): mixed => new RedisConnection(port: -1)];
        yield 'port 65536' => [static fn(): mixed => new RedisConnection(port: 65536)];
        yield 'database -1' => [static fn(): mixed => new RedisConnection(database: -1)];
        yield 'timeout -0.001' => [static fn(): mixed => new RedisConnection(timeout: -0.001)];
        yield 'timeout INF' => [static fn(): mixed => new RedisConnection(timeout: INF)];
        yield 'readTimeout -0.001' => [static fn(): mixed => new RedisConnection(readTimeout: -0.001)];
        yield 'zlib threshold -1' => [static fn(): mixed => new ZlibCompressor(threshold: -1)];
        yield 'zlib level -2' => [static fn(): mixed => new ZlibCompressor(level: -2)];
        yield 'zlib level 10' => [static fn(): mixed => new ZlibCompressor(level: 10)];
        yield 'xfetch beta 0' => [static fn(): mixed => new XFetchPrevention(beta: 0.0)];
        yield 'xfetch beta NAN' => [static fn(): mixed => new XFetchPrevention(beta: NAN)];
        yield 'fifo capacity -1' => [static fn(): mixed => new FifoPolicy(capacity: -1)];
        yield 'lfu capacity -1' => [static fn(): mixed => new LfuPolicy(capacity: -1)];
        yield 'lru capacity -1' => [static fn(): mixed => new LruPolicy(capacity: -1)];
        yield 'sieve capacity -1' => [static fn(): mixed => new SievePolicy(capacity: -1)];
    }

    /**
     * @param \Closure(): mixed $build
     */
    #[DataProvider('valuesPastTheEdge')]
    public function testValuesPastTheEdgeAreRejected(\Closure $build): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(ConfigurationException::INVALID_OPTION);

        $build();
    }

    public function testZlibCompressesFromTheDefaultThresholdOn(): void
    {
        $zlib = new ZlibCompressor();

        self::assertSame("\x00", $zlib->compress(str_repeat('a', 1023))[0], 'below the threshold: stored raw');
        self::assertSame("\x01", $zlib->compress(str_repeat('a', 1024))[0], 'at the threshold: compressed');
        self::assertSame(str_repeat('a', 1024), $zlib->decompress($zlib->compress(str_repeat('a', 1024))));
    }

    public function testZlibEdgesAreAccepted(): void
    {
        $data = str_repeat('b', 64);

        foreach ([new ZlibCompressor(threshold: 0), new ZlibCompressor(level: -1), new ZlibCompressor(level: 0), new ZlibCompressor(level: 9)] as $zlib) {
            self::assertSame($data, $zlib->decompress($zlib->compress($data)));
        }

        self::assertSame("\x01", (new ZlibCompressor(threshold: 0, level: 9))->compress($data)[0]);
    }

    public function testPolicyDefaultsAndUnboundedCapacity(): void
    {
        foreach ([new FifoPolicy(), new LfuPolicy(), new LruPolicy(), new SievePolicy()] as $policy) {
            self::assertSame(10_000, $policy->capacity, $policy->name());
        }

        foreach ([new FifoPolicy(capacity: 0), new LfuPolicy(capacity: 0), new LruPolicy(capacity: 0), new SievePolicy(capacity: 0)] as $policy) {
            self::assertSame(0, $policy->capacity, $policy->name());
        }
    }

    public function testEnvelopesDefaultToZeroCostAndExpireStrictlyAfterTheDeadline(): void
    {
        $entry = new CacheEntry(value: 1, createdAt: 10.0, expiresAt: 20.0);

        self::assertSame(0.0, $entry->delta);
        self::assertSame(0.0, (new TaggedEntry(value: 1, tagVersions: []))->delta);
        self::assertFalse($entry->isExpiredAt(20.0), 'at the deadline the entry is still valid');
        self::assertTrue($entry->isExpiredAt(20.001));
    }

    public function testAtTheExactExpiryEveryStrategyRecomputes(): void
    {
        self::assertTrue((new XFetchPrevention())->shouldRecompute(key: 'k', currentTime: 100.0, expiryTime: 100.0, delta: 0.0));
        self::assertTrue((new NullPrevention())->shouldRecompute(key: 'k', currentTime: 100.0, expiryTime: 100.0, delta: 0.0));
        self::assertFalse((new XFetchPrevention())->shouldRecompute(key: 'k', currentTime: 99.0, expiryTime: 100.0, delta: 0.0), 'no measured cost, no early recompute');
    }

    public function testScopeErrorsNameTheViolatedConstraint(): void
    {
        foreach (['' => 'must not be empty', 'a{b' => 'must not contain'] as $scope => $constraint) {
            try {
                new ScopedCache(new Cache(new ArrayAdapter()), (string) $scope);
                self::fail("scope '{$scope}' must be rejected");
            } catch (ConfigurationException $e) {
                self::assertStringContainsString($constraint, $e->getMessage());
            }
        }
    }

    public function testDecompressionDetailIsAppendedOnlyWhenGiven(): void
    {
        self::assertStringContainsString('bad header', BackendException::decompressionFailed('bad header')->getMessage());
        self::assertStringNotContainsString(': .', BackendException::decompressionFailed(null)->getMessage());
    }
}
