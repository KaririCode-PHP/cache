<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Config\DTO;

use KaririCode\Cache\Config\DTO\ValidationConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ValidationConfig DTO.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 */
#[CoversClass(ValidationConfig::class)]
final class ValidationConfigTest extends TestCase
{
    /**
     * Tests that the DTO can be created with default values when no config is provided.
     */
    public function testCanBeCreatedWithDefaultValues(): void
    {
        // Act
        $config = ValidationConfig::fromConfig([]);

        // Assert
        $this->assertSame(250, $config->maxKeyLength);
        $this->assertSame(2097152, $config->maxValueSize);
        $this->assertSame(['{}()', '/', '@', ':'], $config->reservedCharacters);
        $this->assertSame('/^[a-zA-Z0-9_\-\.]+$/', $config->keyPattern);
    }

    /**
     * Tests that the DTO properties are correctly set from a configuration array.
     */
    public function testCanBeCreatedWithSpecificValues(): void
    {
        // Arrange
        $data = [
            'max_key_length' => 500,
            'max_value_size' => 4000000,
            'reserved_characters' => ['#', '$'],
            'key_pattern' => '/^[a-z]+$/',
        ];

        // Act
        $config = ValidationConfig::fromConfig($data);

        // Assert
        $this->assertSame(500, $config->maxKeyLength);
        $this->assertSame(4000000, $config->maxValueSize);
        $this->assertSame(['#', '$'], $config->reservedCharacters);
        $this->assertSame('/^[a-z]+$/', $config->keyPattern);
    }
}
