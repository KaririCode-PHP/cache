<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Serialization;

use KaririCode\Cache\Exception\BackendException;
use KaririCode\Cache\Serialization\PhpSerializer;
use KaririCode\Cache\ValueObject\CacheEntry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The allowlist admits the listed classes plus the package's envelopes,
 * malformed payloads fail with the parser's diagnostic, and the global
 * error handler is left as it was.
 */
final class PhpSerializerHardeningTest extends TestCase
{
    public function testAllowedClassesAndEnvelopesComeBackAsThemselves(): void
    {
        $serializer = new PhpSerializer(allowedClasses: [\ArrayObject::class]);

        self::assertInstanceOf(\ArrayObject::class, $serializer->unserialize($serializer->serialize(new \ArrayObject([1]))));
        self::assertInstanceOf(CacheEntry::class, $serializer->unserialize($serializer->serialize(new CacheEntry(value: 1, createdAt: 0.0))));
    }

    #[Group('security')]
    public function testTrailingBytesAreRejectedWithTheParserDiagnostic(): void
    {
        try {
            (new PhpSerializer())->unserialize('i:1;garbage');
            self::fail('trailing bytes must be rejected');
        } catch (BackendException $e) {
            self::assertSame(BackendException::SERIALIZATION_FAILED, $e->getCode());
            self::assertStringContainsString('offset', $e->getMessage());
        }
    }

    public function testSerializedFalseIsAValueNotAFailure(): void
    {
        self::assertFalse((new PhpSerializer())->unserialize('b:0;'));
    }

    public function testTheErrorHandlerIsRestoredAfterAFailure(): void
    {
        $marker = static fn(): bool => true;
        set_error_handler($marker);

        try {
            (new PhpSerializer())->unserialize('not serialized');
        } catch (BackendException) {
        }

        $current = set_error_handler(null);
        restore_error_handler();
        restore_error_handler();

        self::assertSame($marker, $current);
    }
}
