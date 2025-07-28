<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

use KaririCode\Exception\AbstractException;

/**
 * Security-related cache exceptions.
 */
final class SecurityException extends AbstractException
{
    private const CODE_RATE_LIMIT_EXCEEDED = 1280;
    private const CODE_SUSPICIOUS_ACTIVITY = 1281;
    private const CODE_ACCESS_DENIED = 1282;
    private const CODE_INVALID_SIGNATURE = 1283;
    private const CODE_ENCRYPTION_FAILED = 1284;
    private const CODE_DECRYPTION_FAILED = 1285;
    private const CODE_KEY_VALIDATION_FAILED = 1286;
    private const CODE_AUDIT_LOG_FAILED = 1287;

    public static function rateLimitExceeded(string $identifier, int $limit, int $current): static
    {
        return static::createException(
            self::CODE_RATE_LIMIT_EXCEEDED,
            'RATE_LIMIT_EXCEEDED',
            "Rate limit exceeded for {$identifier}. Limit: {$limit}/min, Current: {$current}/min"
        );
    }

    public static function suspiciousActivity(string $ip, string $activity): static
    {
        return static::createException(
            self::CODE_SUSPICIOUS_ACTIVITY,
            'SUSPICIOUS_ACTIVITY',
            "Suspicious activity detected from IP {$ip}: {$activity}"
        );
    }

    public static function accessDenied(string $resource, string $reason = ''): static
    {
        $message = "Access denied to resource: {$resource}";
        if ($reason) {
            $message .= " ({$reason})";
        }

        return static::createException(
            self::CODE_ACCESS_DENIED,
            'ACCESS_DENIED',
            $message
        );
    }

    public static function invalidSignature(string $key): static
    {
        return static::createException(
            self::CODE_INVALID_SIGNATURE,
            'INVALID_SIGNATURE',
            "Invalid signature for cache key: {$key}"
        );
    }

    public static function encryptionFailed(string $key, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_ENCRYPTION_FAILED,
            'ENCRYPTION_FAILED',
            "Failed to encrypt data for key: {$key}",
            $previous
        );
    }

    public static function decryptionFailed(string $key, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_DECRYPTION_FAILED,
            'DECRYPTION_FAILED',
            "Failed to decrypt data for key: {$key}",
            $previous
        );
    }

    public static function keyValidationFailed(string $key, string $reason): static
    {
        return static::createException(
            self::CODE_KEY_VALIDATION_FAILED,
            'KEY_VALIDATION_FAILED',
            "Key validation failed for '{$key}': {$reason}"
        );
    }

    public static function auditLogFailed(string $operation, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_AUDIT_LOG_FAILED,
            'AUDIT_LOG_FAILED',
            "Failed to write audit log for operation: {$operation}",
            $previous
        );
    }
}
