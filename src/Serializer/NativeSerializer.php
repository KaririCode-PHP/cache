<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serializer;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\SerializationException;
use KaririCode\Cache\ValueObject\SerializationScheme;
use KaririCode\Contract\ImmutableValue;

/**
 * Native PHP serializer hardened against object-injection and runtime errors.
 */
final class NativeSerializer implements CacheSerializer
{
    /**
     * @param array<class-string> $allowedClasses a list of classes that are allowed to be unserialized
     * @param array<string, mixed> $options options to pass to the native serialize() function
     */
    public function __construct(
        private readonly array $allowedClasses = [],
        private readonly array $options = []
    ) {
    }

    /**
     * @throws CacheException if serialization fails
     */
    public function serialize(mixed $data): string
    {
        try {
            return serialize($data);
        } catch (\Throwable $e) {
            throw CacheException::serializationFailed(get_debug_type($data), $e);
        }
    }

    /**
     * @throws CacheException if deserialization fails or the input is malformed
     */
    public function unserialize(string $data): mixed
    {
        try {
            $unserializeOptions = ['allowed_classes' => $this->allowedClasses];
            $result = @unserialize($data, $unserializeOptions);

            if (false === $result && 'b:0;' !== $data) {
                throw SerializationException::deserializationFailed($data, new \UnexpectedValueException('Malformed serialized data'));
            }

            return $result;
        } catch (\Throwable $e) {
            throw CacheException::deserializationFailed('native', $e);
        }
    }

    public function canSerialize(mixed $data): bool
    {
        // Explicitly check for resources, as serialize() does not throw for them.
        if (is_resource($data)) {
            return false;
        }

        try {
            serialize($data);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getMimeType(): string
    {
        return 'application/vnd.php.serialized';
    }

    public function getConfiguration(): ImmutableValue
    {
        return new SerializationScheme(
            type: 'native',
            allowedClasses: $this->allowedClasses,
            options: $this->options
        );
    }
}
