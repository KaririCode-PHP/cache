<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service\Event;

use KaririCode\Cache\Config\DTO\EventServiceConfig;
use KaririCode\Cache\Contract\Event\EventLogger;
use KaririCode\Contract\Logging\Logger;
use KaririCode\Logging\LoggerRegistry;
use KaririCode\Logging\SilentLogger;

/**
 * Handles all event logging operations.
 */
final class CacheEventLogger implements EventLogger
{
    private readonly Logger $cacheLogger;
    private readonly Logger $performanceLogger;
    private readonly Logger $errorLogger;
    private readonly Logger $asyncLogger;
    private readonly Logger $fallbackLogger;

    public function __construct(
        ?LoggerRegistry $loggerRegistry,
        private readonly EventServiceConfig $config
    ) {
        $this->initializeLoggers($loggerRegistry);
    }

    public function logCacheOperation(string $operation, string $message, array $context): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $logger = match ($operation) {
            'cache_hit', 'cache_set', 'atomic_operation' => $this->asyncLogger,
            'cache_miss' => $this->asyncLogger,
            'cache_delete' => $context['operation_success'] ?? false ? $this->asyncLogger : $this->cacheLogger,
            'cache_clear', 'tag_invalidation' => $this->cacheLogger,
            default => $this->cacheLogger,
        };

        $level = match ($operation) {
            'cache_hit', 'cache_set' => 'debug',
            'cache_miss', 'cache_clear', 'tag_invalidation' => 'info',
            'cache_delete' => $context['operation_success'] ?? false ? 'debug' : 'warning',
            'atomic_operation' => 'debug',
            default => 'info',
        };

        $this->executeLoggingWithFallback(
            fn () => $logger->$level($message, $context),
            "{$operation} logging failed",
            $context
        );
    }

    public function logPerformanceWarning(string $operation, float $duration, float $threshold, array $context): void
    {
        if (!$this->config->performanceLoggingEnabled) {
            return;
        }

        $durationMs = round($duration * 1000, 3);
        $severity = $this->calculateSeverity($durationMs, $threshold);

        $performanceContext = array_merge($context, [
            'performance_alert' => true,
            'threshold_ms' => $threshold,
            'actual_duration_ms' => $durationMs,
            'slowness_factor' => round($durationMs / $threshold, 2),
            'severity' => $severity,
        ]);

        $this->executeLoggingWithFallback(
            fn () => $this->performanceLogger->warning("Slow cache operation detected: {$operation}", $performanceContext),
            'Performance logging failed',
            $performanceContext
        );
    }

    public function logError(string $message, array $context): void
    {
        try {
            $this->errorLogger->error($message, $context);
        } catch (\Throwable $e) {
            try {
                $this->fallbackLogger->error('Logging subsystem failure', [
                    'original_message' => $message,
                    'logging_error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // Silent failure - logging infrastructure is completely down
            }
        }
    }

    private function initializeLoggers(?LoggerRegistry $loggerRegistry): void
    {
        $this->fallbackLogger = $loggerRegistry?->getLogger('system') ?? new SilentLogger();

        if (null === $loggerRegistry) {
            $this->cacheLogger = $this->fallbackLogger;
            $this->performanceLogger = $this->fallbackLogger;
            $this->errorLogger = $this->fallbackLogger;
            $this->asyncLogger = $this->fallbackLogger;

            return;
        }

        $this->cacheLogger = $loggerRegistry->getLogger('cache') ?? $this->fallbackLogger;
        $this->performanceLogger = $loggerRegistry->getLogger('performance') ?? $this->fallbackLogger;
        $this->errorLogger = $loggerRegistry->getLogger('error') ?? $this->fallbackLogger;
        $this->asyncLogger = $loggerRegistry->getLogger('async') ?? $this->fallbackLogger;
    }

    private function executeLoggingWithFallback(callable $logOperation, string $errorMessage, array $context): void
    {
        try {
            $logOperation();
        } catch (\Throwable $e) {
            $this->logError($errorMessage, [
                'error' => $e->getMessage(),
                'context_keys' => array_keys($context),
            ]);
        }
    }

    private function calculateSeverity(float $actualMs, float $thresholdMs): string
    {
        $factor = $actualMs / $thresholdMs;

        return match (true) {
            $factor >= 5.0 => 'critical',
            $factor >= 3.0 => 'high',
            $factor >= 2.0 => 'medium',
            default => 'low',
        };
    }
}
