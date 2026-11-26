<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Component;

use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Contract\Logging\Logger;

/**
 * Centralized error handling and retry logic.
 * Single Responsibility: Error handling and operation retry.
 */
final class RedisErrorHandler
{
    public function __construct(
        private Logger $logger,
        private RedisConfig $config
    ) {
    }

    /**
     * Execute operation with retry logic and proper error handling.
     */
    public function executeWithRetry(
        callable $operation,
        RedisConnectionManager $connectionManager,
        string $operationName = 'unknown',
        string $key = ''
    ): mixed {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->config->retryAttempts; ++$attempt) {
            try {
                $connectionManager->ensureConnection();

                return $operation();
            } catch (\RedisException $e) {
                $lastException = $e;
                $this->logError($e, $operationName, $key, $attempt);

                if ($attempt < $this->config->retryAttempts) {
                    $this->waitBeforeRetry($attempt);
                }
            }
        }

        throw CacheException::operationFailed($operationName, $key, $lastException);
    }

    private function logError(\RedisException $e, string $operation, string $key, int $attempt): void
    {
        $this->logger->error('Redis operation error', [
            'operation' => $operation,
            'key' => $key,
            'attempt' => $attempt,
            'max_attempts' => $this->config->retryAttempts,
            'error' => $e->getMessage(),
        ]);
    }

    private function waitBeforeRetry(int $attempt): void
    {
        $delay = $this->config->retryDelay * $attempt;
        usleep((int) ($delay * 1000000));
    }
}
