<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Serialization;

use KaririCode\Cache\Exception\BackendException;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Serialization\IgbinarySerializer;
use KaririCode\Cache\Serialization\PhpSerializer;
use KaririCode\Cache\ValueObject\CacheEntry;
use KaririCode\Cache\ValueObject\TaggedEntry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('security')]
final class SerializerSafetyTest extends TestCase
{
    public function testAllowlistAlwaysAdmitsThePackageEnvelopes(): void
    {
        $serializer = new PhpSerializer(allowedClasses: []);

        $entry = $serializer->unserialize(serialize(CacheEntry::withTtl(42, 60, microtime(true))));
        $tagged = $serializer->unserialize(serialize(new TaggedEntry('v', ['t' => 1])));

        self::assertInstanceOf(CacheEntry::class, $entry);
        self::assertSame(42, $entry->value);
        self::assertInstanceOf(TaggedEntry::class, $tagged);
    }

    public function testClassesOutsideTheAllowlistStayIncomplete(): void
    {
        $serializer = new PhpSerializer(allowedClasses: []);

        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $serializer->unserialize(serialize(new \ArrayObject())));
    }

    public function testMalformedPayloadRaisesInsteadOfWarning(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionCode(BackendException::SERIALIZATION_FAILED);

        (new PhpSerializer())->unserialize('this is not serialized');
    }

    public function testUnserializableValueRaisesSerializationFailed(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionCode(BackendException::SERIALIZATION_FAILED);

        (new PhpSerializer())->serialize(static fn(): int => 1);
    }

    public function testSerializedFalseIsNotMistakenForFailure(): void
    {
        self::assertFalse((new PhpSerializer())->unserialize(serialize(false)));
    }

    public function testIgbinaryRoundTripAndMalformedPayload(): void
    {
        if (!extension_loaded('igbinary')) {
            self::markTestSkipped('ext-igbinary not loaded');
        }

        $serializer = new IgbinarySerializer();
        self::assertSame(['a' => [1, 2]], $serializer->unserialize($serializer->serialize(['a' => [1, 2]])));

        $this->expectException(CacheException::class);
        $serializer->unserialize('garbage');
    }
}
