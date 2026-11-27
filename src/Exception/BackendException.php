<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

use KaririCode\Contract\Exception\Runtime;

/**
 * A backend operation or the payload encoding failed while running: the
 * backend refused or timed out, or a stored payload could not be
 * (un)serialized or decompressed.
 *
 * Adapters raise it for I/O; for unreadable payloads they catch it, delete
 * the entry and report a miss. The cause is kept as the previous exception.
 *
 * ARFA 1.3 P5 (Continuous Observability): codes 8600-8605.
 * Spec V4: exception, family Runtime (§8.4); semantic factories.
 *
 * Preconditions: none.
 * Thread-safety: immutable once thrown.
 *
 * Side effects: none.
 *
 * Example:
 *     throw BackendException::readFailed($redisException);
 *
 * References: ADR-013; SPEC-001 §11.
 *
 * @see https://www.php.net/manual/en/class.runtimeexception.php
 */
final class BackendException extends \RuntimeException implements CacheException, Runtime
{
    public const int READ_FAILED = 8600;
    public const int WRITE_FAILED = 8601;
    public const int DELETE_FAILED = 8602;
    public const int FLUSH_FAILED = 8603;
    public const int SERIALIZATION_FAILED = 8604;
    public const int DECOMPRESSION_FAILED = 8605;

    public static function readFailed(?\Throwable $previous = null): self
    {
        return new self(message: 'Cache read failed.', code: self::READ_FAILED, previous: $previous);
    }

    public static function writeFailed(?\Throwable $previous = null): self
    {
        return new self(message: 'Cache write failed.', code: self::WRITE_FAILED, previous: $previous);
    }

    public static function deleteFailed(?\Throwable $previous = null): self
    {
        return new self(message: 'Cache delete failed.', code: self::DELETE_FAILED, previous: $previous);
    }

    public static function flushFailed(?\Throwable $previous = null): self
    {
        return new self(message: 'Cache flush failed.', code: self::FLUSH_FAILED, previous: $previous);
    }

    /**
     * @param string $reason Engine diagnostic (offsets, class names), never the payload.
     */
    public static function serializationFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(message: "Cache payload could not be (un)serialized: {$reason}", code: self::SERIALIZATION_FAILED, previous: $previous);
    }

    public static function decompressionFailed(?string $detail = null): self
    {
        $suffix = null === $detail ? '' : ": {$detail}";

        return new self(message: "Compressed cache payload could not be decompressed{$suffix}", code: self::DECOMPRESSION_FAILED);
    }
}
