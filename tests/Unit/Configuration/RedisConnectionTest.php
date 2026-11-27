<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Configuration;

use KaririCode\Cache\Configuration\RedisConnection;
use KaririCode\Cache\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisConnectionTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'empty host' => [['host' => '']];
        yield 'negative port' => [['port' => -1]];
        yield 'port above 65535' => [['port' => 70_000]];
        yield 'negative database' => [['database' => -1]];
        yield 'negative timeout' => [['timeout' => -1.0]];
        yield 'non-finite read timeout' => [['readTimeout' => INF]];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsFailFast(array $settings): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(ConfigurationException::INVALID_OPTION);

        new RedisConnection(...$settings);
    }

    public function testDefaultsAndPublicSettings(): void
    {
        $connection = new RedisConnection(host: 'cache.internal', port: 6380, database: 3, password: 's3cret');

        self::assertSame('cache.internal', $connection->host);
        self::assertSame(6380, $connection->port);
        self::assertSame(3, $connection->database);
        self::assertSame(2.0, $connection->timeout);
        self::assertFalse((new \ReflectionProperty($connection, 'password'))->isPublic(), 'the password is never exposed');
    }
}
