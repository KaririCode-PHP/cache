<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config\DTO;

use KaririCode\Cache\Config\DTO\LayeredDriverConfig;
use KaririCode\Cache\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the LayeredDriverConfig DTO.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 */
#[CoversClass(LayeredDriverConfig::class)]
final class LayeredDriverConfigTest extends TestCase
{
    /**
     * Tests creation with default values.
     */
    public function testCanBeCreatedWithDefaultValues(): void
    {
        // Act
        $config = LayeredDriverConfig::fromConfig([]);

        // Assert
        $this->assertTrue($config->promotionEnabled);
        $this->assertSame(3, $config->promotionThreshold);
        $this->assertTrue($config->circuitBreakerEnabled);
        $this->assertSame(5, $config->maxFailures);
        $this->assertSame(60, $config->circuitTimeout);
    }

    /**
     * Tests creation with specific values.
     */
    public function testCanBeCreatedWithSpecificValues(): void
    {
        // Arrange
        $data = [
            'promotion_enabled' => false,
            'promotion_threshold' => 10,
            'circuit_breaker_enabled' => false,
            'max_failures' => 20,
            'circuit_timeout' => 120,
        ];

        // Act
        $config = LayeredDriverConfig::fromConfig($data);

        // Assert
        $this->assertFalse($config->promotionEnabled);
        $this->assertSame(10, $config->promotionThreshold);
        $this->assertFalse($config->circuitBreakerEnabled);
        $this->assertSame(20, $config->maxFailures);
        $this->assertSame(120, $config->circuitTimeout);
    }

    /**
     * Tests that the DTO's validation logic throws an exception for invalid options.
     *
     * @param array<string, mixed> $invalidData
     */
    #[DataProvider('invalidOptionsProvider')]
    public function testThrowsExceptionForInvalidOptions(array $invalidData): void
    {
        // Assert
        $this->expectException(ConfigurationException::class);

        // Act
        LayeredDriverConfig::fromConfig($invalidData);
    }

    /**
     * Provides sets of invalid configuration options to test the validation logic.
     *
     * @return array<string, array<array<string, int>>>
     */
    public static function invalidOptionsProvider(): array
    {
        return [
            'promotion threshold zero' => [['promotion_threshold' => 0]],
            'promotion threshold negative' => [['promotion_threshold' => -1]],
            'max failures zero' => [['max_failures' => 0]],
            'max failures negative' => [['max_failures' => -5]],
            'circuit timeout zero' => [['circuit_timeout' => 0]],
            'circuit timeout negative' => [['circuit_timeout' => -60]],
        ];
    }
}
