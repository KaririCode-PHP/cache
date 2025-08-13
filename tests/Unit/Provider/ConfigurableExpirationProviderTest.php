<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Provider;

use KaririCode\Cache\Config\DTO\ExpirationProviderConfig;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Provider\ConfigurableExpirationProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ConfigurableExpirationProvider.
 *
 * This test suite validates all public methods and edge cases of the
 * ConfigurableExpirationProvider, ensuring proper TTL calculation,
 * validation, and strategy-based expiration logic according to SOLID
 * principles and Clean Code practices.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(ConfigurableExpirationProvider::class)]
final class ConfigurableExpirationProviderTest extends TestCase
{
    private const ONE_HOUR_IN_SECONDS = 3600;
    private const ONE_DAY_IN_SECONDS = 86400;
    private const TEN_MINUTES_IN_SECONDS = 600;
    private const THIRTY_MINUTES_IN_SECONDS = 1800;
    private const TWO_HOURS_IN_SECONDS = 7200;
    private const FOUR_HOURS_IN_SECONDS = 14400;

    private ExpirationProviderConfig $defaultConfig;
    private ExpirationProviderConfig $customConfig;
    private ExpirationProviderConfig $slidingConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultConfig = ExpirationProviderConfig::fromConfig([]);

        $this->customConfig = ExpirationProviderConfig::fromConfig([
            'default_ttl' => self::ONE_HOUR_IN_SECONDS,
            'operation_ttls' => [
                'set' => self::THIRTY_MINUTES_IN_SECONDS,
                'increment' => self::TWO_HOURS_IN_SECONDS,
            ],
            'key_pattern_ttls' => [
                'session:*' => self::FOUR_HOURS_IN_SECONDS,
                'user:*:profile' => self::ONE_DAY_IN_SECONDS,
            ],
            'sliding_expiration' => false,
            'max_ttl' => self::ONE_DAY_IN_SECONDS,
        ]);

        $this->slidingConfig = ExpirationProviderConfig::fromConfig([
            'default_ttl' => self::ONE_HOUR_IN_SECONDS,
            'sliding_expiration' => true,
        ]);
    }

    // ========================================================================
    // Constructor and Configuration Tests
    // ========================================================================

    public function testCanBeCreatedFromConfigDto(): void
    {
        // Act
        $provider = ConfigurableExpirationProvider::fromConfig($this->defaultConfig);

        // Assert
        $this->assertInstanceOf(ConfigurableExpirationProvider::class, $provider);
    }

    #[DataProvider('invalidConfigProvider')]
    public function testThrowsExceptionForInvalidConfiguration(array $configData, string $expectedMessage): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        // Arrange
        $configDto = ExpirationProviderConfig::fromConfig($configData);

        // Act
        ConfigurableExpirationProvider::fromConfig($configDto);
    }

    // ========================================================================
    // calculateExpiration() Tests - Priority Logic
    // ========================================================================

    public function testCalculateExpirationUsesExplicitTtlFirst(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->customConfig);
        $explicitTtl = self::TEN_MINUTES_IN_SECONDS;
        $expectedTime = new \DateTimeImmutable("+{$explicitTtl} seconds");

        // Act
        $result = $provider->calculateExpiration($explicitTtl, 'session:123', 'set');

        // Assert
        $this->assertEqualsWithDelta($expectedTime->getTimestamp(), $result->getTimestamp(), 1);
    }

    public function testCalculateExpirationUsesKeyPatternTtlSecond(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->customConfig);
        $expectedTime = new \DateTimeImmutable('+' . self::FOUR_HOURS_IN_SECONDS . ' seconds');

        // Act
        $result = $provider->calculateExpiration(null, 'session:123', 'set');

        // Assert
        $this->assertEqualsWithDelta($expectedTime->getTimestamp(), $result->getTimestamp(), 1);
    }

    public function testCalculateExpirationUsesOperationTtlThird(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->customConfig);
        $expectedTime = new \DateTimeImmutable('+' . self::THIRTY_MINUTES_IN_SECONDS . ' seconds');

        // Act
        $result = $provider->calculateExpiration(null, 'other:key', 'set');

        // Assert
        $this->assertEqualsWithDelta($expectedTime->getTimestamp(), $result->getTimestamp(), 1);
    }

    public function testCalculateExpirationUsesDefaultTtlAsFallback(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->customConfig);
        $expectedTime = new \DateTimeImmutable('+' . self::ONE_HOUR_IN_SECONDS . ' seconds');

        // Act
        $result = $provider->calculateExpiration(null, 'other:key', 'unknown_op');

        // Assert
        $this->assertEqualsWithDelta($expectedTime->getTimestamp(), $result->getTimestamp(), 1);
    }

    // ========================================================================
    // TTL Conversion and Validation Tests
    // ========================================================================

    #[DataProvider('validTtlProvider')]
    public function testToSecondsConvertsValidTtl(mixed $ttl, ?int $expectedSeconds): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->defaultConfig);

        // Act & Assert
        $this->assertEquals($expectedSeconds, $provider->toSeconds($ttl));
    }

    public function testToSecondsConvertsDateTimeWithDelta(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->defaultConfig);
        $ttl = new \DateTimeImmutable('+10 minutes');
        $expectedSeconds = self::TEN_MINUTES_IN_SECONDS;

        // Act
        $actualSeconds = $provider->toSeconds($ttl);

        // Assert
        $this->assertEqualsWithDelta($expectedSeconds, $actualSeconds, 1);
    }

    #[DataProvider('invalidTtlProvider')]
    public function testToSecondsThrowsExceptionForInvalidTtl(mixed $invalidTtl): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->defaultConfig);

        // Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        $provider->toSeconds($invalidTtl);
    }

    public function testFromSecondsCreatesCorrectDateTime(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->defaultConfig);
        $seconds = 900;
        $expectedTime = new \DateTimeImmutable("+{$seconds} seconds");

        // Act
        $result = $provider->fromSeconds($seconds);

        // Assert
        $this->assertEqualsWithDelta($expectedTime->getTimestamp(), $result->getTimestamp(), 1);
    }

    public function testValidateTtlAcceptsValidValues(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->defaultConfig);

        // Act & Assert
        $this->assertTrue($provider->validateTtl(3600));
        $this->assertTrue($provider->validateTtl(null));
        $this->assertTrue($provider->validateTtl(new \DateInterval('PT1H')));
    }

    public function testValidateTtlRejectsInvalidValues(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->customConfig);

        // Act & Assert
        $this->assertFalse($provider->validateTtl(-10));
        $this->assertFalse($provider->validateTtl(self::ONE_DAY_IN_SECONDS + 1));
    }

    // ========================================================================
    // isExpired() Tests
    // ========================================================================

    public function testIsExpiredReturnsCorrectly(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->defaultConfig);
        $future = new \DateTimeImmutable('+1 hour');
        $past = new \DateTimeImmutable('-1 hour');

        // Act & Assert
        $this->assertFalse($provider->isExpired($future));
        $this->assertTrue($provider->isExpired($past));
        $this->assertFalse($provider->isExpired(null)); // Never expires
    }

    // ========================================================================
    // Sliding Expiration Tests
    // ========================================================================

    public function testUpdateSlidingExpirationDoesNothingWhenDisabled(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->customConfig);
        $currentExpiration = new \DateTimeImmutable('+1 hour');

        // Act
        $newExpiration = $provider->updateSlidingExpiration($currentExpiration);

        // Assert
        $this->assertSame($currentExpiration, $newExpiration);
    }

    public function testUpdateSlidingExpirationExtendsTtlWhenEnabled(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->slidingConfig);
        $currentExpiration = new \DateTimeImmutable('+30 minutes');
        $expectedNewExpiration = new \DateTimeImmutable('+' . self::ONE_HOUR_IN_SECONDS . ' seconds');

        // Act
        $newExpiration = $provider->updateSlidingExpiration($currentExpiration);

        // Assert
        $this->assertEqualsWithDelta($expectedNewExpiration->getTimestamp(), $newExpiration->getTimestamp(), 1);
    }

    public function testUpdateSlidingExpirationDoesNotExtendAlreadyExpiredItem(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->slidingConfig);
        $pastExpiration = new \DateTimeImmutable('-10 minutes');

        // Act
        $newExpiration = $provider->updateSlidingExpiration($pastExpiration);

        // Assert
        $this->assertSame($pastExpiration, $newExpiration);
    }

    // ========================================================================
    // Getter Methods Tests
    // ========================================================================

    public function testGettersReturnCorrectValues(): void
    {
        // Arrange
        $provider = ConfigurableExpirationProvider::fromConfig($this->customConfig);

        // Act & Assert
        $this->assertSame(self::ONE_HOUR_IN_SECONDS, $provider->getDefaultTtl());
        $this->assertSame(self::THIRTY_MINUTES_IN_SECONDS, $provider->getOperationTtl('set'));
        $this->assertNull($provider->getOperationTtl('nonexistent_op'));
        $this->assertSame(self::FOUR_HOURS_IN_SECONDS, $provider->getKeyPatternTtl('session:abc'));
        $this->assertNull($provider->getKeyPatternTtl('unknown:pattern'));
        $this->assertFalse($provider->isSlidingExpiration());
        $this->assertTrue(ConfigurableExpirationProvider::fromConfig($this->slidingConfig)->isSlidingExpiration());
        $this->assertIsArray($provider->getConfiguration());
    }

    // ========================================================================
    // Data Providers
    // ========================================================================

    public static function invalidConfigProvider(): array
    {
        return [
            'default TTL too low' => [
                ['default_ttl' => 0],
                'Default TTL must be at least 1 second(s)',
            ],
            'max TTL too low' => [
                ['max_ttl' => 0],
                'Max TTL must be at least 1 second(s)',
            ],
            'default TTL exceeds max TTL' => [
                ['default_ttl' => 100, 'max_ttl' => 50],
                'Default TTL cannot exceed max TTL',
            ],
            'operation TTL exceeds max TTL' => [
                ['default_ttl' => 50, 'operation_ttls' => ['set' => 200], 'max_ttl' => 100],
                'Operation TTL for "set" cannot exceed max TTL',
            ],
            'pattern TTL exceeds max TTL' => [
                ['default_ttl' => 50, 'key_pattern_ttls' => ['user:*' => 300], 'max_ttl' => 100],
                'Pattern TTL for "user:*" cannot exceed max TTL',
            ],
        ];
    }

    public static function validTtlProvider(): array
    {
        return [
            'null' => [null, null],
            'integer' => [300, 300],
            'DateInterval' => [new \DateInterval('PT1H'), self::ONE_HOUR_IN_SECONDS],
        ];
    }

    public static function invalidTtlProvider(): array
    {
        return [
            'negative integer' => [-10],
            'past DateTime' => [new \DateTimeImmutable('-1 minute')],
            'string' => ['invalid'],
            'object' => [new \stdClass()],
        ];
    }
}
