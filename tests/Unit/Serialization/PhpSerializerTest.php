<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Serialization;

use KaririCode\Cache\Serialization\PhpSerializer;
use PHPUnit\Framework\TestCase;

final class PhpSerializerTest extends TestCase
{
    private PhpSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new PhpSerializer();
    }

    public function testSerializeAndUnserializeString(): void
    {
        $serialized = $this->serializer->serialize('hello');

        self::assertSame('hello', $this->serializer->unserialize($serialized));
    }

    public function testSerializeAndUnserializeArray(): void
    {
        $data = ['nested' => ['a' => 1, 'b' => [2, 3]]];
        $serialized = $this->serializer->serialize($data);

        self::assertSame($data, $this->serializer->unserialize($serialized));
    }

    public function testSerializeAndUnserializeInteger(): void
    {
        $serialized = $this->serializer->serialize(42);

        self::assertSame(42, $this->serializer->unserialize($serialized));
    }

    public function testSerializeNull(): void
    {
        $serialized = $this->serializer->serialize(null);

        self::assertNull($this->serializer->unserialize($serialized));
    }

    public function testSerializeObject(): void
    {
        $object = new \stdClass();
        $object->name = 'test';

        $serialized = $this->serializer->serialize($object);
        $result = $this->serializer->unserialize($serialized);

        self::assertInstanceOf(\stdClass::class, $result);
        self::assertSame('test', $result->name);
    }

    public function testNameReturnsPhp(): void
    {
        self::assertSame('php', $this->serializer->name());
    }
}
