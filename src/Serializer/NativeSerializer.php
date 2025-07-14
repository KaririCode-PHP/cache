<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serializer;

use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Contract\Cache\CacheSerializer;
use KaririCode\Contract\ImmutableValue;

/**
 * Native PHP serializer hardened against object‑injection and runtime errors.
 *
 * @psalm-type Serializable = scalar|array|object|null
 */
final class NativeSerializer implements CacheSerializer, ImmutableValue
{
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
            // get_debug_type() gives a precise textual representation of the value's type.
            throw CacheException::serializationFailed(get_debug_type($data), $e);
        }
    }

    /**
     * @throws CacheException if deserialization fails or the input is malformed
     */
    public function unserialize(string $data): mixed
    {
        try {
            // Prevent object‑injection by disallowing object instantiation.
            $result = @unserialize($data, [
                'allowed_classes' => false,
            ]);

            // When unserialize() fails it returns false *and* raises a warning. However, the
            // serialized boolean false ("b:0;") also evaluates to false. We distinguish the two.
            if (false === $result && 'b:0;' !== $data) {
                throw new \UnexpectedValueException('Failed to unserialize data');
            }

            return $result;
        } catch (\Throwable $e) {
            throw CacheException::deserializationFailed('native', $e);
        }
    }

    public function canSerialize(mixed $data): bool
    {
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
        return new SerializerConfiguration([
            'type' => 'native',
            'allowed_classes' => $this->allowedClasses,
            'options' => $this->options,
        ]);
    }
}
