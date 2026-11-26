<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Event;

/**
 * Contract for event logging operations.
 */
interface EventLogger
{
    public function logCacheOperation(string $operation, string $message, array $context): void;

    public function logPerformanceWarning(string $operation, float $duration, float $threshold, array $context): void;

    public function logError(string $message, array $context): void;
}
