<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Service\Event;

use KaririCode\Cache\Config\DTO\EventServiceConfig;
use KaririCode\Cache\Service\Event\CacheEventContextBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheEventContextBuilder::class)]
final class CacheEventContextBuilderTest extends TestCase
{
    private EventServiceConfig $config;
    private CacheEventContextBuilder $contextBuilder;

    protected function setUp(): void
    {
        $this->config = new EventServiceConfig(sensitiveKeys: ['password', 'token']);
        $this->contextBuilder = new CacheEventContextBuilder($this->config);
    }

    public function testBuildContextHasCoreFields(): void
    {
        // Act
        $context = $this->contextBuilder->buildContext('test_key', 'test_event');

        // Assert
        $this->assertArrayHasKey('component', $context);
        $this->assertArrayHasKey('event_type', $context);
        $this->assertArrayHasKey('cache_key', $context);
        $this->assertArrayHasKey('timestamp', $context);
        $this->assertSame('test_event', $context['event_type']);
        $this->assertSame('test_key', $context['cache_key']);
    }

    public function testSanitizeContextRedactsSensitiveKeys(): void
    {
        // Arrange
        $dirtyContext = [
            'username' => 'testuser',
            'password' => 'secret123',
            'data' => ['token' => 'abc-token'],
        ];

        // Act
        $cleanContext = $this->contextBuilder->sanitizeContext($dirtyContext);

        // Assert
        $this->assertSame('testuser', $cleanContext['username']);
        $this->assertSame('[REDACTED]', $cleanContext['password']);
        $this->assertSame('[REDACTED]', $cleanContext['data']['token']);
    }

    public function testSanitizeContextTruncatesLargeContexts(): void
    {
        // Arrange
        $smallLimitConfig = new EventServiceConfig(maxContextSize: 50);
        $builder = new CacheEventContextBuilder($smallLimitConfig);
        $largeContext = ['long_key' => str_repeat('x', 100)];

        // Act
        $sanitized = $builder->sanitizeContext($largeContext);

        // Assert
        $this->assertTrue($sanitized['_truncated']);
        $this->assertGreaterThan(50, $sanitized['_original_size']);
    }

    #[DataProvider('valueSizeProvider')]
    public function testCalculateValueSize(mixed $value, int $expectedSize): void
    {
        // Act
        $size = $this->contextBuilder->calculateValueSize($value);

        // Assert
        $this->assertEquals($expectedSize, $size);
    }

    #[DataProvider('ttlFormattingProvider')]
    public function testFormatTtlHuman(int $ttl, string $expectedFormat): void
    {
        // Act
        $formatted = $this->contextBuilder->formatTtlHuman($ttl);

        // Assert
        $this->assertSame($expectedFormat, $formatted);
    }

    public static function valueSizeProvider(): array
    {
        return [
            'string' => ['hello', 5],
            'integer' => [123, 3],
            'array' => [['a', 'b'], strlen(serialize(['a', 'b']))],
            'unserializable' => [fn () => '', 0],
        ];
    }

    public static function ttlFormattingProvider(): array
    {
        return [
            'seconds' => [30, '30s'],
            'minutes' => [120, '2m'],
            'hours' => [7200, '2h'],
            'days' => [86400, '1d'],
            'zero_ttl' => [0, 'no_expiration'],
        ];
    }
}
