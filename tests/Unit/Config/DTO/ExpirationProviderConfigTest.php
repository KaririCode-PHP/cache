<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config\DTO;

use KaririCode\Cache\Config\DTO\ExpirationProviderConfig;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ExpirationProviderConfig DTO.
 *
 * Tests the immutable DTO that encapsulates configuration for the expiration provider,
 * ensuring proper validation, default values, and TTL handling according to
 * SOLID principles and Clean Code practices.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
#[CoversClass(ExpirationProviderConfig::class)]
final class ExpirationProviderConfigTest extends TestCase
{
    private const ONE_HOUR = 3600;
    private const ONE_DAY = 86400;
    private const ONE_WEEK = 604800;

    /**
     * Tests that the DTO can be created with default values when no config is provided.
     *
     * This test ensures that the factory method provides sensible defaults
     * following the Fail-Safe Defaults principle.
     */
    public function testCanBeCreatedWithDefaultValues(): void
    {
        // Act
        $config = ExpirationProviderConfig::fromConfig([]);

        // Assert
        $this->assertSame(self::ONE_HOUR, $config->defaultTtl); // 1 hour default
        $this->assertEmpty($config->operationTtls);
        $this->assertEmpty($config->keyPatternTtls);
        $this->assertFalse($config->slidingExpiration);
        $this->assertSame(ExpirationProvider::MAX_TTL_SECONDS, $config->maxTtl);
    }

    /**
     * Tests that the DTO properties are correctly set from a configuration array.
     *
     * This test verifies the Value Object pattern implementation and ensures
     * that all properties are correctly mapped from configuration.
     */
    public function testCanBeCreatedWithSpecificValues(): void
    {
        // Arrange
        $data = [
            'default_ttl' => self::ONE_DAY,
            'operation_ttls' => [
                'set' => self::ONE_HOUR,
                'increment' => 900, // 15 minutes
                'batch_set' => 7200, // 2 hours
            ],
            'key_pattern_ttls' => [
                'user:*' => self::ONE_WEEK,
                'session:*' => 1800, // 30 minutes
                'temp:*' => 300, // 5 minutes
            ],
            'sliding_expiration' => true,
            'max_ttl' => 2592000, // 30 days
        ];

        // Act
        $config = ExpirationProviderConfig::fromConfig($data);

        // Assert
        $this->assertSame(self::ONE_DAY, $config->defaultTtl);
        $this->assertSame([
            'set' => self::ONE_HOUR,
            'increment' => 900,
            'batch_set' => 7200,
        ], $config->operationTtls);
        $this->assertSame([
            'user:*' => self::ONE_WEEK,
            'session:*' => 1800,
            'temp:*' => 300,
        ], $config->keyPatternTtls);
        $this->assertTrue($config->slidingExpiration);
        $this->assertSame(2592000, $config->maxTtl);
    }

    /**
     * Tests that the DTO correctly handles partial configuration arrays.
     *
     * This test ensures that the factory method gracefully handles
     * incomplete configuration by using defaults for missing values.
     */
    public function testCanBeCreatedWithPartialConfiguration(): void
    {
        // Arrange
        $data = [
            'default_ttl' => 7200,
            'operation_ttls' => ['set' => 1800],
            // Missing key_pattern_ttls, sliding_expiration, max_ttl
        ];

        // Act
        $config = ExpirationProviderConfig::fromConfig($data);

        // Assert
        $this->assertSame(7200, $config->defaultTtl);
        $this->assertSame(['set' => 1800], $config->operationTtls);
        $this->assertEmpty($config->keyPatternTtls); // Default value
        $this->assertFalse($config->slidingExpiration); // Default value
        $this->assertSame(ExpirationProvider::MAX_TTL_SECONDS, $config->maxTtl); // Default value
    }

    /**
     * Tests that the DTO properly handles type casting from configuration.
     *
     * This test ensures robustness when dealing with configuration values
     * that may come from different sources with different types.
     */
    public function testCorrectlyHandlesTypeCasting(): void
    {
        // Arrange - Simulating values that might come from environment variables
        $data = [
            'default_ttl' => '3600', // String that should be cast to integer
            'sliding_expiration' => '1', // String that should be cast to boolean
            'max_ttl' => '86400', // String that should be cast to integer
        ];

        // Act
        $config = ExpirationProviderConfig::fromConfig($data);

        // Assert
        $this->assertSame(3600, $config->defaultTtl);
        $this->assertTrue($config->slidingExpiration);
        $this->assertSame(86400, $config->maxTtl);
    }

    /**
     * Tests various operation-specific TTL configurations.
     *
     * This test ensures that different cache operations can have
     * their own specific TTL settings for fine-grained control.
     */
    public function testSupportsOperationSpecificTtls(): void
    {
        // Arrange
        $data = [
            'operation_ttls' => [
                'get' => 0, // No additional TTL for gets
                'set' => 3600, // 1 hour for sets
                'add' => 1800, // 30 minutes for adds
                'replace' => 900, // 15 minutes for replaces
                'increment' => 600, // 10 minutes for increments
                'decrement' => 600, // 10 minutes for decrements
                'delete' => 300, // 5 minutes for deletes (tombstone)
            ],
        ];

        // Act
        $config = ExpirationProviderConfig::fromConfig($data);

        // Assert
        $this->assertArrayHasKey('get', $config->operationTtls);
        $this->assertArrayHasKey('set', $config->operationTtls);
        $this->assertArrayHasKey('add', $config->operationTtls);
        $this->assertArrayHasKey('replace', $config->operationTtls);
        $this->assertArrayHasKey('increment', $config->operationTtls);
        $this->assertArrayHasKey('decrement', $config->operationTtls);
        $this->assertArrayHasKey('delete', $config->operationTtls);

        $this->assertSame(0, $config->operationTtls['get']);
        $this->assertSame(3600, $config->operationTtls['set']);
        $this->assertSame(300, $config->operationTtls['delete']);
    }

    /**
     * Tests various key pattern TTL configurations.
     *
     * This test ensures that cache keys matching specific patterns
     * can have their own TTL settings for better cache management.
     */
    public function testSupportsKeyPatternTtls(): void
    {
        // Arrange
        $data = [
            'key_pattern_ttls' => [
                'user:profile:*' => self::ONE_DAY,
                'user:session:*' => self::ONE_HOUR,
                'temp:*' => 300,
                'cache:*' => 1800,
                'api:response:*' => 600,
            ],
        ];

        // Act
        $config = ExpirationProviderConfig::fromConfig($data);

        // Assert
        $this->assertArrayHasKey('user:profile:*', $config->keyPatternTtls);
        $this->assertArrayHasKey('user:session:*', $config->keyPatternTtls);
        $this->assertArrayHasKey('temp:*', $config->keyPatternTtls);
        $this->assertArrayHasKey('cache:*', $config->keyPatternTtls);
        $this->assertArrayHasKey('api:response:*', $config->keyPatternTtls);

        $this->assertSame(self::ONE_DAY, $config->keyPatternTtls['user:profile:*']);
        $this->assertSame(300, $config->keyPatternTtls['temp:*']);
        $this->assertSame(600, $config->keyPatternTtls['api:response:*']);
    }

    /**
     * Tests that the DTO is truly immutable.
     *
     * This test verifies that all properties are readonly and the object
     * cannot be modified after instantiation, following Value Object principles.
     */
    public function testIsImmutable(): void
    {
        // Arrange
        $config = ExpirationProviderConfig::fromConfig(['default_ttl' => 3600]);

        // Assert - Verify all properties are readonly
        $reflection = new \ReflectionClass($config);
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), "Property {$property->getName()} should be readonly");
        }
    }

    /**
     * Tests sliding expiration configuration.
     *
     * This test ensures that sliding expiration (where TTL is reset on access)
     * can be properly configured.
     */
    public function testSlidingExpirationConfiguration(): void
    {
        // Arrange & Act
        $enabledConfig = ExpirationProviderConfig::fromConfig(['sliding_expiration' => true]);
        $disabledConfig = ExpirationProviderConfig::fromConfig(['sliding_expiration' => false]);
        $defaultConfig = ExpirationProviderConfig::fromConfig([]);

        // Assert
        $this->assertTrue($enabledConfig->slidingExpiration);
        $this->assertFalse($disabledConfig->slidingExpiration);
        $this->assertFalse($defaultConfig->slidingExpiration); // Default should be false
    }
}
