<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Component;

use KaririCode\Cache\Exception\CacheException;
use Psr\Log\LoggerInterface;

/**
 * Centralized error handling for APCu operations.
 * Provides retry logic and consistent error reporting.
 */
final class ApcuErrorHandler
{
    private const TRANSIENT_ERROR_PATTERNS = [
        'Memory allocation failed',
        'Unable to allocate memory',
    ];

    public function __construct(
        private LoggerInterface $logger,
        private int $maxRetries = 3,
        private float $retryDelay = 0.1
    ) {
    }

    /**
     * Execute an operation with retry logic for transient failures.
     */
    public function executeWithRetry(
        callable $operation,
        string $operationName,
        string $key = ''
    ): mixed {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; ++$attempt) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                $lastException = $this->handleException($e, $operationName, $key, $attempt);

                if (!$this->isTransientError($e) || $attempt === $this->maxRetries) {
                    throw $lastException;
                }

                $this->sleep($attempt);
            }
        }

        throw $lastException ?? CacheException::operationFailed($operationName, $key);
    }

    private function handleException(
        \Throwable $e,
        string $operationName,
        string $key,
        int $attempt
    ): CacheException {
        $this->logger->warning(
            'APCu operation failed',
            [
                'operation' => $operationName,
                'key' => $key,
                'attempt' => $attempt,
                'error' => $e->getMessage(),
            ]
        );

        if ($e instanceof CacheException) {
            return $e;
        }

        return CacheException::operationFailed($operationName, $key, $e);
    }

    private function isTransientError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        foreach (self::TRANSIENT_ERROR_PATTERNS as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function sleep(int $attempt): void
    {
        $delay = min(1000, $this->retryDelay * 1000 * (2 ** ($attempt - 1)));
        usleep((int) $delay * 1000);
    }
}
