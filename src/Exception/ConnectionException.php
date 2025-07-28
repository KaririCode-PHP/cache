<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

use KaririCode\Exception\AbstractException;

final class ConnectionException extends AbstractException
{
    private const CODE_CONNECTION_FAILED = 1290;
    private const CODE_AUTHENTICATION_FAILED = 1291;

    public static function connectionFailed(string $backend, string $details, ?\Throwable $previous = null): self
    {
        return self::createException(
            self::CODE_CONNECTION_FAILED,
            'CONNECTION_FAILED',
            "Failed to connect to {$backend}: {$details}",
            $previous
        );
    }

    public static function authenticationFailed(string $backend, string $details, ?\Throwable $previous = null): self
    {
        return self::createException(
            self::CODE_AUTHENTICATION_FAILED,
            'AUTHENTICATION_FAILED',
            "Authentication failed for {$backend}: {$details}",
            $previous
        );
    }
}
