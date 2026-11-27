<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Integration\Adapter;

use KaririCode\Cache\Configuration\RedisConnection;
use KaririCode\Cache\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A connection the server refuses fails at open(), including the refusals
 * phpredis reports with false: a database index the server does not have
 * must never fall back to database 0.
 */
final class RedisConnectionTest extends TestCase
{
    private string $host;

    private int $port;

    protected function setUp(): void
    {
        $this->host = (string) (getenv('KARIRICODE_CACHE_REDIS_HOST') ?: '127.0.0.1');
        $this->port = (int) (getenv('KARIRICODE_CACHE_REDIS_PORT') ?: 6379);

        try {
            (new RedisConnection(host: $this->host, port: $this->port, timeout: 0.5))->open()->close();
        } catch (ConnectionException) {
            self::markTestSkipped('Redis not reachable');
        }
    }

    #[Group('security')]
    public function testAMissingDatabaseFailsInsteadOfUsingDatabaseZero(): void
    {
        try {
            (new RedisConnection(host: $this->host, port: $this->port, database: 999))->open();
            self::fail('database 999 must be refused');
        } catch (ConnectionException $e) {
            self::assertSame(ConnectionException::CONNECTION_FAILED, $e->getCode());
            self::assertInstanceOf(\RedisException::class, $e->getPrevious());
        }
    }

    #[Group('security')]
    public function testARejectedPasswordFailsTheConnection(): void
    {
        try {
            (new RedisConnection(host: $this->host, port: $this->port, password: 'not-the-password'))->open();
            self::fail('a rejected AUTH must fail the connection');
        } catch (ConnectionException $e) {
            self::assertSame(ConnectionException::CONNECTION_FAILED, $e->getCode());
            self::assertStringNotContainsString('not-the-password', $e->getMessage());
        }
    }

    public function testAnExistingDatabaseIsSelected(): void
    {
        $redis = (new RedisConnection(host: $this->host, port: $this->port, database: 15))->open();

        self::assertSame(15, $redis->getDbNum());
        $redis->close();
    }
}
