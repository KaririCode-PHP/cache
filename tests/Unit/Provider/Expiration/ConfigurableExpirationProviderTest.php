<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Provider\Expiration;

use KaririCode\Cache\Config\DTO\ExpirationProviderConfig;
use KaririCode\Cache\Provider\Expiration\ConfigurableExpirationProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for ConfigurableExpirationProvider.
 *
 * Demonstrates how the refactored provider coordinates
 * its dependencies while maintaining the same public interface.
 */
#[CoversClass(ConfigurableExpirationProvider::class)]
final class ConfigurableExpirationProviderTest extends TestCase
{
    private ExpirationProviderConfig $config;
    private ConfigurableExpirationProvider $provider;

    protected function setUp(): void
    {
        $this->config = ExpirationProviderConfig::fromConfig([
            'default_ttl' => 3600,
            'operation_ttls' => [
                'set' => 1800,
                'increment' => 7200,
            ],
            'key_pattern_ttls' => [
                'user:*' => 14400,
                'session:*' => 1800,
            ],
            'sliding_expiration' => false,
            'max_ttl' => 86400,
        ]);
        $this->provider = ConfigurableExpirationProvider::fromConfig($this->config);
    }

    // ========================================================================
    // Factory Method Tests
    // ========================================================================

    public function testFromConfigCreatesProviderCorrectly(): void
    {
        // Act
        $provider = ConfigurableExpirationProvider::fromConfig($this->config);

        // Assert
        $this->assertInstanceOf(ConfigurableExpirationProvider::class, $provider);
        $this->assertSame(3600, $provider->getDefaultTtl());
    }

    // ========================================================================
    // Priority-based TTL Resolution Tests
    // ========================================================================

    public function testCalculateExpirationUsesExplicitTtlFirst(): void
    {
        // Arrange
        $explicitTtl = 1200;
        $expectedTime = new \DateTimeImmutable('+20 minutes');

        // Act
        $result = $this->provider->calculateExpiration($explicitTtl, 'user:123', 'set');

        // Assert
        $this->assertEqualsWithDelta(
            $expectedTime->getTimestamp(),
            $result->getTimestamp(),
            1
        );
    }

    public function testCalculateExpirationUsesKeyPatternSecond(): void
    {
        // Arrange
        $key = 'user:123';
        $expectedTime = new \DateTimeImmutable('+4 hours'); // 14400 seconds

        // Act
        $result = $this->provider->calculateExpiration(null, $key, 'set');

        // Assert
        $this->assertEqualsWithDelta(
            $expectedTime->getTimestamp(),
            $result->getTimestamp(),
            1
        );
    }

    public function testCalculateExpirationUsesOperationThird(): void
    {
        // Arrange
        $operation = 'set';
        $expectedTime = new \DateTimeImmutable('+30 minutes'); // 1800 seconds

        // Act
        $result = $this->provider->calculateExpiration(null, 'other:key', $operation);

        // Assert
        $this->assertEqualsWithDelta(
            $expectedTime->getTimestamp(),
            $result->getTimestamp(),
            1
        );
    }

    public function testCalculateExpirationUsesDefaultAsFallback(): void
    {
        // Arrange
        $expectedTime = new \DateTimeImmutable('+1 hour'); // 3600 seconds

        // Act
        $result = $this->provider->calculateExpiration(null, 'other:key', 'unknown');

        // Assert
        $this->assertEqualsWithDelta(
            $expectedTime->getTimestamp(),
            $result->getTimestamp(),
            1
        );
    }

    // ========================================================================
    // Constraint Application Tests
    // ========================================================================

    public function testCalculateExpirationAppliesMaxTtlConstraint(): void
    {
        // Arrange - TTL exceeds max_ttl of 86400
        $largeTtl = 100000;
        $expectedTime = new \DateTimeImmutable('+1 day'); // Should be capped at 86400

        // Act
        $result = $this->provider->calculateExpiration($largeTtl);

        // Assert
        $this->assertEqualsWithDelta(
            $expectedTime->getTimestamp(),
            $result->getTimestamp(),
            1
        );
    }

    // ========================================================================
    // New Interface Methods Tests
    // ========================================================================

    public function testIsSlidingExpirationReturnsCorrectValue(): void
    {
        // Arrange
        $nonSlidingProvider = ConfigurableExpirationProvider::fromConfig($this->config);
        $slidingConfig = ExpirationProviderConfig::fromConfig([
            'default_ttl' => 3600,
            'sliding_expiration' => true,
        ]);
        $slidingProvider = ConfigurableExpirationProvider::fromConfig($slidingConfig);

        // Act & Assert
        $this->assertFalse($nonSlidingProvider->isSlidingExpiration());
        $this->assertTrue($slidingProvider->isSlidingExpiration());
    }

    public function testGetConfigurationReturnsCorrectArray(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->config);

        // Act
        $result = $provider->getConfiguration();

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('default_ttl', $result);
        $this->assertArrayHasKey('operation_ttls', $result);
        $this->assertArrayHasKey('key_pattern_ttls', $result);
        $this->assertArrayHasKey('sliding_expiration', $result);
        $this->assertArrayHasKey('max_ttl', $result);
        $this->assertSame(3600, $result['default_ttl']);
        $this->assertSame(['set' => 1800, 'increment' => 7200], $result['operation_ttls']);
        $this->assertSame(['user:*' => 14400, 'session:*' => 1800], $result['key_pattern_ttls']);
        $this->assertFalse($result['sliding_expiration']);
        $this->assertSame(86400, $result['max_ttl']);
    }

    public function testUpdateSlidingExpirationWithParameters(): void
    {
        // Arrange
        $slidingConfig = ExpirationProviderConfig::fromConfig([
            'default_ttl' => 3600,
            'sliding_expiration' => true,
        ]);
        $provider = ConfigurableExpirationProvider::fromConfig($slidingConfig);
        $currentExpiration = new \DateTimeImmutable('+30 minutes');
        $expectedTime = new \DateTimeImmutable('+1 hour');

        // Act
        $result = $provider->updateSlidingExpiration($currentExpiration, 'test:key', ['context' => 'value']);

        // Assert
        $this->assertEqualsWithDelta(
            $expectedTime->getTimestamp(),
            $result->getTimestamp(),
            1
        );
    }

    // ========================================================================
    // Backward Compatibility Tests
    // ========================================================================

    public function testGetOperationTtlMaintainsBackwardCompatibility(): void
    {
        // Act & Assert
        $this->assertSame(1800, $this->provider->getOperationTtl('set'));
        $this->assertSame(7200, $this->provider->getOperationTtl('increment'));
        $this->assertNull($this->provider->getOperationTtl('nonexistent'));
    }

    public function testGetKeyPatternTtlMaintainsBackwardCompatibility(): void
    {
        // Act & Assert
        $this->assertSame(14400, $this->provider->getKeyPatternTtl('user:123'));
        $this->assertSame(1800, $this->provider->getKeyPatternTtl('session:abc'));
        $this->assertNull($this->provider->getKeyPatternTtl('other:key'));
    }

    // ========================================================================
    // Delegation Tests (verifying composition works correctly)
    // ========================================================================

    public function testToSecondsDelegate(): void
    {
        // Act
        $result = $this->provider->toSeconds(3600);

        // Assert
        $this->assertSame(3600, $result);
    }

    public function testFromSecondsDelegate(): void
    {
        // Arrange
        $expectedTime = new \DateTimeImmutable('+1 hour');

        // Act
        $result = $this->provider->fromSeconds(3600);

        // Assert
        $this->assertEqualsWithDelta(
            $expectedTime->getTimestamp(),
            $result->getTimestamp(),
            1
        );
    }

    public function testValidateTtlDelegate(): void
    {
        // Act & Assert
        $this->assertTrue($this->provider->validateTtl(3600));
        $this->assertTrue($this->provider->validateTtl(null));
        $this->assertFalse($this->provider->validateTtl(-100));
    }

    public function testIsExpiredDelegate(): void
    {
        // Arrange
        $future = new \DateTimeImmutable('+1 hour');
        $past = new \DateTimeImmutable('-1 hour');

        // Act & Assert
        $this->assertFalse($this->provider->isExpired($future));
        $this->assertTrue($this->provider->isExpired($past));
        $this->assertFalse($this->provider->isExpired(null));
    }
}
