<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Provider\Expiration;

use KaririCode\Cache\Provider\Expiration\TtlConverter;
use KaririCode\Cache\Provider\Expiration\TtlResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for TtlResolver.
 *
 * Demonstrates improved testability through separation of concerns:
 * - Strategy testing is isolated from conversion logic
 * - Each resolution method can be tested independently
 * - Clear behavioral contracts
 */
#[CoversClass(TtlResolver::class)]
final class TtlResolverTest extends TestCase
{
    private TtlResolver $resolver;
    private TtlConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new TtlConverter();
        $this->resolver = new TtlResolver($this->converter);
    }

    // ========================================================================
    // Explicit TTL Resolution Tests
    // ========================================================================

    public function testResolveFromExplicitWithInteger(): void
    {
        // Arrange
        $ttl = 3600;

        // Act
        $result = $this->resolver->resolveFromExplicit($ttl);

        // Assert
        $this->assertSame(3600, $result);
    }

    public function testResolveFromExplicitWithNullReturnsNull(): void
    {
        // Act
        $result = $this->resolver->resolveFromExplicit(null);

        // Assert
        $this->assertNull($result);
    }

    // ========================================================================
    // Key Pattern Resolution Tests
    // ========================================================================

    #[DataProvider('keyPatternProvider')]
    public function testResolveFromKeyPattern(string $key, array $patterns, ?int $expected): void
    {
        // Act
        $result = $this->resolver->resolveFromKeyPattern($key, $patterns);

        // Assert
        $this->assertSame($expected, $result);
    }

    public static function keyPatternProvider(): array
    {
        $patterns = [
            'user:*' => 7200,
            'session:*' => 1800,
            'api:*:cache' => 3600,
        ];

        return [
            'matches user pattern' => ['user:123', $patterns, 7200],
            'matches session pattern' => ['session:abc', $patterns, 1800],
            'matches api pattern' => ['api:v1:cache', $patterns, 3600],
            'no pattern match' => ['other:key', $patterns, null],
            'empty patterns' => ['user:123', [], null],
        ];
    }

    // ========================================================================
    // Operation Resolution Tests
    // ========================================================================

    public function testResolveFromOperationWithExistingOperation(): void
    {
        // Arrange
        $operations = ['set' => 3600, 'increment' => 1800];
        $operation = 'set';

        // Act
        $result = $this->resolver->resolveFromOperation($operation, $operations);

        // Assert
        $this->assertSame(3600, $result);
    }

    public function testResolveFromOperationWithNonExistentOperation(): void
    {
        // Arrange
        $operations = ['set' => 3600];
        $operation = 'unknown';

        // Act
        $result = $this->resolver->resolveFromOperation($operation, $operations);

        // Assert
        $this->assertNull($result);
    }

    // ========================================================================
    // Default Resolution Tests
    // ========================================================================

    public function testResolveDefaultReturnsTtl(): void
    {
        // Arrange
        $defaultTtl = 3600;

        // Act
        $result = $this->resolver->resolveDefault($defaultTtl);

        // Assert
        $this->assertSame(3600, $result);
    }
}
