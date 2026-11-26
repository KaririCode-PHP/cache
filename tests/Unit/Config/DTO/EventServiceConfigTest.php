<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config\DTO;

use KaririCode\Cache\Config\DTO\EventServiceConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the EventServiceConfig DTO.
 *
 * This test suite covers all aspects of the EventServiceConfig DTO,
 * including its factories, validation, and helper methods, ensuring
 * its reliability and adherence to Clean Code principles.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(EventServiceConfig::class)]
final class EventServiceConfigTest extends TestCase
{
    /**
     * Tests that the DTO is created with sensible defaults when no configuration is provided.
     */
    public function testCreationWithDefaultValues(): void
    {
        // Act
        $config = EventServiceConfig::fromConfig([]);

        // Assert
        $this->assertTrue($config->enabled);
        $this->assertTrue($config->logOnDispatchError);
        $this->assertTrue($config->performanceLoggingEnabled);
        $this->assertTrue($config->asyncLoggingEnabled);
        $this->assertTrue($config->structuredLoggingEnabled);
        $this->assertNotEmpty($config->performanceThresholds);
        $this->assertNotEmpty($config->enabledChannels);
        $this->assertNotEmpty($config->sensitiveKeys);
        $this->assertSame(1024, $config->maxContextSize);
        $this->assertFalse($config->includeStackTrace);
        $this->assertSame('debug', $config->logLevel);
    }

    /**
     * Tests that the DTO properties are correctly set from a specific configuration array.
     */
    public function testCreationWithSpecificValues(): void
    {
        // Arrange
        // FIX: Keys are now in snake_case to match the fromConfig() factory method.
        $data = [
            'enabled' => false,
            'log_on_dispatch_error' => false,
            'performance_logging_enabled' => false,
            'async_logging_enabled' => false,
            'structured_logging_enabled' => false,
            'performance_thresholds' => ['cache_hit' => 20.0],
            'enabled_channels' => ['error'],
            'sensitive_keys' => ['api_key'],
            'max_context_size' => 512,
            'include_stack_trace' => true,
            'log_level' => 'error',
        ];

        // Act
        $config = EventServiceConfig::fromConfig($data);

        // Assert
        $this->assertFalse($config->enabled);
        $this->assertFalse($config->logOnDispatchError);
        $this->assertFalse($config->performanceLoggingEnabled);
        $this->assertFalse($config->asyncLoggingEnabled);
        $this->assertFalse($config->structuredLoggingEnabled);
        $this->assertSame(['cache_hit' => 20.0], $config->performanceThresholds);
        $this->assertSame(['error'], $config->enabledChannels);
        $this->assertSame(['api_key'], $config->sensitiveKeys);
        $this->assertSame(512, $config->maxContextSize);
        $this->assertTrue($config->includeStackTrace);
        $this->assertSame('error', $config->logLevel);
    }

    /**
     * Tests the factory method for production environments.
     */
    public function testForProductionFactorySetsStrictDefaults(): void
    {
        // Act
        $config = EventServiceConfig::forProduction();

        // Assert
        $this->assertFalse($config->includeStackTrace);
        $this->assertSame('info', $config->logLevel);
        $this->assertSame(5.0, $config->performanceThresholds['cache_hit']); // Stricter threshold
        $this->assertSame(512, $config->maxContextSize);
    }

    /**
     * Tests the factory method for development environments.
     */
    public function testForDevelopmentFactorySetsLenientDefaults(): void
    {
        // Act
        $config = EventServiceConfig::forDevelopment();

        // Assert
        $this->assertTrue($config->includeStackTrace);
        $this->assertSame('debug', $config->logLevel);
        $this->assertSame(50.0, $config->performanceThresholds['cache_hit']); // Lenient threshold
        $this->assertSame(2048, $config->maxContextSize);
    }

    /**
     * Tests that the constructor throws an exception for invalid configuration values.
     *
     * @param array<string, mixed> $invalidData The invalid configuration array
     */
    #[DataProvider('invalidConfigProvider')]
    public function testThrowsExceptionForInvalidConfiguration(array $invalidData, string $expectedMessage): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        // Act
        // FIX: Use the `fromConfig` factory which correctly handles array data and defaults.
        // This prevents the TypeError seen in the test failures.
        EventServiceConfig::fromConfig($invalidData);
    }

    /**
     * Data provider for invalid configuration scenarios.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidConfigProvider(): array
    {
        return [
            // FIX: Keys are now in snake_case to match the `fromConfig` factory method.
            'invalid log level' => [['log_level' => 'invalid_level'], 'Invalid log level: invalid_level'],
            'negative max context size' => [['max_context_size' => -1], 'Max context size must be non-negative'],
            'invalid performance threshold key' => [['performance_thresholds' => [0 => 10.0]], 'Invalid performance threshold for operation: 0'],
            'negative performance threshold value' => [['performance_thresholds' => ['op' => -5.0]], 'Invalid performance threshold for operation: op'],
            'empty channel name' => [['enabled_channels' => ['cache', ' ']], 'Invalid channel name:  '],
        ];
    }

    /**
     * Tests the isChannelEnabled helper method.
     */
    public function testIsChannelEnabled(): void
    {
        // Arrange
        $config = EventServiceConfig::fromConfig(['enabled_channels' => ['cache', 'error']]);

        // Act & Assert
        $this->assertTrue($config->isChannelEnabled('cache'));
        $this->assertTrue($config->isChannelEnabled('error'));
        $this->assertFalse($config->isChannelEnabled('performance'));
    }

    /**
     * Tests the getPerformanceThreshold helper method.
     */
    public function testGetPerformanceThreshold(): void
    {
        // Arrange
        $config = EventServiceConfig::fromConfig(['performance_thresholds' => ['cache_set' => 75.0]]);

        // Act & Assert
        $this->assertSame(75.0, $config->getPerformanceThreshold('cache_set'));
        $this->assertSame(100.0, $config->getPerformanceThreshold('unknown_op')); // Tests default fallback
    }

    /**
     * Tests the isSensitiveKey helper method.
     */
    public function testIsSensitiveKey(): void
    {
        // Arrange
        $config = EventServiceConfig::fromConfig(['sensitive_keys' => ['password', 'secret_key']]);

        // Act & Assert
        $this->assertTrue($config->isSensitiveKey('user_password'));
        $this->assertTrue($config->isSensitiveKey('SECRET_KEY')); // Should be case-insensitive
        $this->assertTrue($config->isSensitiveKey('password'));
        $this->assertFalse($config->isSensitiveKey('username'));
        $this->assertFalse($config->isSensitiveKey('user_secret'));
    }

    /**
     * Tests that the DTO is truly immutable.
     */
    public function testIsImmutable(): void
    {
        // Arrange
        $reflection = new \ReflectionClass(EventServiceConfig::class);

        // Act & Assert
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), "Property {$property->getName()} should be readonly");
        }
    }
}
