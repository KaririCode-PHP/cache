<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Component;

use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\ConnectionException;
use Psr\Log\LoggerInterface;

/**
 * Centralized error handling for Memcached operations.
 * Implements retry logic and error mapping following SRP.
 */
final class MemcachedErrorHandler
{
    private const TRANSIENT_ERROR_CODES = [
        \Memcached::RES_TIMEOUT,
        \Memcached::RES_HOST_LOOKUP_FAILURE,
        \Memcached::RES_CONNECTION_FAILURE,
        \Memcached::RES_SERVER_TEMPORARILY_DISABLED,
    ];

    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Executes a Memcached operation with retry logic for transient errors.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function executeWithRetry(
        callable $operation,
        MemcachedConnectionManager $connectionManager,
        string $operationName,
        string $key = ''
    ): mixed {
        $attempts = 0;
        $maxAttempts = 3;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            ++$attempts;

            try {
                $connectionManager->ensureConnection();

                return $operation();
            } catch (ConnectionException $e) {
                $lastException = $e;
                if ($attempts < $maxAttempts) {
                    $this->logger->warning('Connection error, attempting reconnection', [
                        'operation' => $operationName,
                        'key' => $key,
                        'attempt' => $attempts,
                        'error' => $e->getMessage(),
                    ]);
                    $this->sleep($attempts);
                    try {
                        $connectionManager->reconnect();
                    } catch (ConnectionException $reconnectException) {
                        $lastException = $reconnectException;
                    }
                }
            } catch (CacheException $e) {
                if (!$this->isTransientError($e)) {
                    throw $e;
                }
                $lastException = $e;
                if ($attempts < $maxAttempts) {
                    $this->logger->warning('Transient error, retrying', [
                        'operation' => $operationName,
                        'key' => $key,
                        'attempt' => $attempts,
                        'error' => $e->getMessage(),
                    ]);
                    $this->sleep($attempts);
                }
            }
        }

        // If the loop completes, an exception is guaranteed to have been caught.
        throw CacheException::operationFailed($operationName, $key, $lastException);
    }

    private function isTransientError(CacheException $exception): bool
    {
        // This is a simplified check. A more robust implementation might inspect
        // a custom exception code or the previous exception from the driver.
        $message = $exception->getMessage();
        foreach (self::TRANSIENT_ERROR_CODES as $errorCode) {
            if (str_contains($message, (string) $errorCode)) {
                return true;
            }
        }

        return false;
    }

    private function sleep(int $attempt): void
    {
        // Exponential backoff with a cap.
        $delay = min(1000, 100 * (2 ** ($attempt - 1))); // Max 1 second
        usleep($delay * 1000);
    }
}
