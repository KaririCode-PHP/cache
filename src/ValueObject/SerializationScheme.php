<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Contract\ImmutableValue;

/**
 * An immutable value object for holding serializer configuration.
 */
final class SerializationScheme implements ImmutableValue
{
    /**
     * @param string $type The type of the serializer (e.g., 'native', 'json').
     * @param string $innerSerializer for decorator serializers, the class of the wrapped serializer
     * @param int $compressionLevel the compression level for compressing serializers
     * @param int $threshold the size threshold to trigger compression
     * @param int $flags the JSON encoding flags
     * @param int $depth the maximum JSON encoding depth
     * @param array<class-string> $allowedClasses allowed classes for native deserialization
     * @param array<string, mixed> $options other miscellaneous options
     */
    public function __construct(
        public readonly string $type,
        public readonly string $innerSerializer = '',
        public readonly int $compressionLevel = 0,
        public readonly int $threshold = 0,
        public readonly int $flags = 0,
        public readonly int $depth = 0,
        public readonly array $allowedClasses = [],
        public readonly array $options = []
    ) {
        $this->validateType();
    }

    /**
     * Validates that the serializer type is not empty.
     *
     * @throws InvalidArgumentException
     */
    private function validateType(): void
    {
        if ('' === trim($this->type)) {
            throw InvalidArgumentException::invalidValue('Serializer type cannot be empty.');
        }
    }
}
