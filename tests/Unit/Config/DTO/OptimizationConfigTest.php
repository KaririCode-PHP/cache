<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config\DTO;

use KaririCode\Cache\Config\DTO\OptimizationConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the OptimizationConfig DTO.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 */
#[CoversClass(OptimizationConfig::class)]
final class OptimizationConfigTest extends TestCase
{
    /**
     * Tests creation with default (empty) values.
     */
    public function testCanBeCreatedWithDefaultValues(): void
    {
        // Act
        $config = OptimizationConfig::fromConfig([]);

        // Assert
        $this->assertEmpty($config->redis);
        $this->assertEmpty($config->memcached);
        $this->assertEmpty($config->filesystem);
        $this->assertEmpty($config->apcu);
    }

    /**
     * Tests creation with specific optimization arrays.
     */
    public function testCanBeCreatedWithSpecificValues(): void
    {
        // Arrange
        $data = [
            'redis' => ['pipeline_batch_size' => 100],
            'memcached' => ['cas_tokens' => true],
            'filesystem' => ['atomic_writes' => true],
            'apcu' => ['slam_defense' => true],
        ];

        // Act
        $config = OptimizationConfig::fromConfig($data);

        // Assert
        $this->assertSame(['pipeline_batch_size' => 100], $config->redis);
        $this->assertSame(['cas_tokens' => true], $config->memcached);
        $this->assertSame(['atomic_writes' => true], $config->filesystem);
        $this->assertSame(['slam_defense' => true], $config->apcu);
    }

    /**
     * Tests the getDriverOptimizations method returns the correct array for each driver.
     */
    public function testGetDriverOptimizationsReturnsCorrectArray(): void
    {
        // Arrange
        $data = [
            'redis' => ['pipeline' => 'enabled'],
            'memcached' => ['binary' => 'enabled'],
        ];
        $config = OptimizationConfig::fromConfig($data);

        // Act & Assert
        $this->assertSame(['pipeline' => 'enabled'], $config->getDriverOptimizations('redis'));
        $this->assertSame(['binary' => 'enabled'], $config->getDriverOptimizations('memcached'));
        $this->assertEmpty($config->getDriverOptimizations('filesystem'));
        $this->assertEmpty($config->getDriverOptimizations('unknown_driver'));
    }
}
