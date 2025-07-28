<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

use KaririCode\Exception\AbstractException;

/**
 * Serialization-specific cache exceptions.
 */
final class SerializationException extends AbstractException
{
    private const CODE_SERIALIZATION_FAILED = 1270;
    private const CODE_DESERIALIZATION_FAILED = 1271;
    private const CODE_COMPRESSION_FAILED = 1272;
    private const CODE_DECOMPRESSION_FAILED = 1273;
    private const CODE_UNSUPPORTED_TYPE = 1274;
    private const CODE_DATA_TOO_LARGE = 1275;
    private const CODE_INVALID_FORMAT = 1276;
    private const CODE_SERIALIZER_NOT_AVAILABLE = 1277;

    public static function serializationFailed(mixed $data, ?\Throwable $previous = null): static
    {
        $type = is_object($data) ? get_class($data) : gettype($data);

        return static::createException(
            self::CODE_SERIALIZATION_FAILED,
            'SERIALIZATION_FAILED',
            "Failed to serialize data of type: {$type}",
            $previous
        );
    }

    public static function deserializationFailed(string $data, ?\Throwable $previous = null): static
    {
        $preview = substr($data, 0, 50) . (strlen($data) > 50 ? '...' : '');

        return static::createException(
            self::CODE_DESERIALIZATION_FAILED,
            'DESERIALIZATION_FAILED',
            "Failed to deserialize data: {$preview}",
            $previous
        );
    }

    public static function compressionFailed(int $dataSize, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_COMPRESSION_FAILED,
            'COMPRESSION_FAILED',
            "Failed to compress data ({$dataSize} bytes)",
            $previous
        );
    }

    public static function decompressionFailed(string $format, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_DECOMPRESSION_FAILED,
            'DECOMPRESSION_FAILED',
            "Failed to decompress data (format: {$format})",
            $previous
        );
    }

    public static function unsupportedType(mixed $data): static
    {
        $type = is_object($data) ? get_class($data) : gettype($data);

        return static::createException(
            self::CODE_UNSUPPORTED_TYPE,
            'UNSUPPORTED_TYPE',
            "Unsupported data type for serialization: {$type}"
        );
    }

    public static function dataTooLarge(int $size, int $maxSize): static
    {
        return static::createException(
            self::CODE_DATA_TOO_LARGE,
            'DATA_TOO_LARGE',
            "Data size ({$size} bytes) exceeds maximum allowed size ({$maxSize} bytes)"
        );
    }

    public static function invalidFormat(string $expectedFormat, string $actualFormat): static
    {
        return static::createException(
            self::CODE_INVALID_FORMAT,
            'INVALID_FORMAT',
            "Invalid data format. Expected: {$expectedFormat}, Got: {$actualFormat}"
        );
    }

    public static function serializerNotAvailable(string $serializerName): static
    {
        return static::createException(
            self::CODE_SERIALIZER_NOT_AVAILABLE,
            'SERIALIZER_NOT_AVAILABLE',
            "Serializer not available: {$serializerName}"
        );
    }
}
