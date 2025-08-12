<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * Enhanced configuration DTO for the EventService with advanced logging capabilities.
 *
 * This configuration integrates with KaririCode's sophisticated logging system
 * to provide fine-grained control over event handling, performance monitoring,
 * and error tracking.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final readonly class EventServiceConfig implements ImmutableValue
{
    /**
     * @param bool $enabled Whether event dispatching and logging is enabled
     * @param bool $logOnDispatchError Whether to log dispatcher errors
     * @param bool $performanceLoggingEnabled Whether to enable performance monitoring
     * @param bool $asyncLoggingEnabled Whether to use async logging for non-critical events
     * @param bool $structuredLoggingEnabled Whether to include rich structured context
     * @param array<string, float> $performanceThresholds Custom performance thresholds (in milliseconds)
     * @param array<string> $enabledChannels Specific logging channels to enable
     * @param array<string> $sensitiveKeys Keys that should be masked in logs for security
     * @param int $maxContextSize Maximum size of context data to prevent memory issues
     * @param bool $includeStackTrace Whether to include stack traces in error logs
     * @param string $logLevel Minimum log level for cache events
     */
    public function __construct(
        public bool $enabled = true,
        public bool $logOnDispatchError = true,
        public bool $performanceLoggingEnabled = true,
        public bool $asyncLoggingEnabled = true,
        public bool $structuredLoggingEnabled = true,
        public array $performanceThresholds = [
            'cache_hit' => 10.0,
            'cache_miss' => 5.0,
            'cache_set' => 50.0,
            'cache_delete' => 25.0,
            'cache_clear' => 500.0,
            'tag_invalidation' => 200.0,
            'atomic_operation' => 100.0,
        ],
        public array $enabledChannels = ['cache', 'performance', 'error', 'async'],
        public array $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth'],
        public int $maxContextSize = 1024,
        public bool $includeStackTrace = false,
        public string $logLevel = 'debug'
    ) {
        $this->validateConfiguration();
    }

    /**
     * Create configuration from array (useful for loading from config files).
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            enabled: $config['enabled'] ?? true,
            logOnDispatchError: $config['log_on_dispatch_error'] ?? true,
            performanceLoggingEnabled: $config['performance_logging_enabled'] ?? true,
            asyncLoggingEnabled: $config['async_logging_enabled'] ?? true,
            structuredLoggingEnabled: $config['structured_logging_enabled'] ?? true,
            performanceThresholds: $config['performance_thresholds'] ?? [
                'cache_hit' => 10.0,
                'cache_miss' => 5.0,
                'cache_set' => 50.0,
                'cache_delete' => 25.0,
                'cache_clear' => 500.0,
                'tag_invalidation' => 200.0,
                'atomic_operation' => 100.0,
            ],
            enabledChannels: $config['enabled_channels'] ?? ['cache', 'performance', 'error', 'async'],
            sensitiveKeys: $config['sensitive_keys'] ?? ['password', 'token', 'secret', 'key', 'auth'],
            maxContextSize: $config['max_context_size'] ?? 1024,
            includeStackTrace: $config['include_stack_trace'] ?? false,
            logLevel: $config['log_level'] ?? 'debug'
        );
    }

    /**
     * Check if a specific logging channel is enabled.
     */
    public function isChannelEnabled(string $channel): bool
    {
        return in_array($channel, $this->enabledChannels, true);
    }

    /**
     * Get performance threshold for a specific operation.
     */
    public function getPerformanceThreshold(string $operation): float
    {
        return $this->performanceThresholds[$operation] ?? 100.0;
    }

    /**
     * Check if a key should be masked for security.
     */
    public function isSensitiveKey(string $key): bool
    {
        $lowerKey = strtolower($key);

        foreach ($this->sensitiveKeys as $sensitivePattern) {
            if (str_contains($lowerKey, strtolower($sensitivePattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get configuration optimized for production environment.
     */
    public static function forProduction(): self
    {
        return new self(
            enabled: true,
            logOnDispatchError: true,
            performanceLoggingEnabled: true,
            asyncLoggingEnabled: true,
            structuredLoggingEnabled: true,
            performanceThresholds: [
                'cache_hit' => 5.0,      // Stricter thresholds for production
                'cache_miss' => 3.0,
                'cache_set' => 25.0,
                'cache_delete' => 15.0,
                'cache_clear' => 200.0,
                'tag_invalidation' => 100.0,
                'atomic_operation' => 50.0,
            ],
            enabledChannels: ['cache', 'performance', 'error'], // No async in production for consistency
            sensitiveKeys: ['password', 'token', 'secret', 'key', 'auth', 'session', 'cookie'],
            maxContextSize: 512,      // Smaller context to save resources
            includeStackTrace: false, // Don't expose stack traces in production
            logLevel: 'info'         // Higher log level for production
        );
    }

    /**
     * Get configuration optimized for development environment.
     */
    public static function forDevelopment(): self
    {
        return new self(
            enabled: true,
            logOnDispatchError: true,
            performanceLoggingEnabled: true,
            asyncLoggingEnabled: false,  // Sync logging for easier debugging
            structuredLoggingEnabled: true,
            performanceThresholds: [
                'cache_hit' => 50.0,     // More lenient thresholds for development
                'cache_miss' => 30.0,
                'cache_set' => 200.0,
                'cache_delete' => 100.0,
                'cache_clear' => 1000.0,
                'tag_invalidation' => 500.0,
                'atomic_operation' => 300.0,
            ],
            enabledChannels: ['cache', 'performance', 'error', 'async'],
            sensitiveKeys: ['password', 'token', 'secret'],
            maxContextSize: 2048,     // Larger context for debugging
            includeStackTrace: true,  // Include stack traces for debugging
            logLevel: 'debug'        // Verbose logging for development
        );
    }

    /**
     * Validate configuration values.
     */
    private function validateConfiguration(): void
    {
        if ($this->maxContextSize < 0) {
            throw new \InvalidArgumentException('Max context size must be non-negative');
        }

        $validLogLevels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
        if (!in_array($this->logLevel, $validLogLevels, true)) {
            throw new \InvalidArgumentException("Invalid log level: {$this->logLevel}");
        }

        foreach ($this->performanceThresholds as $operation => $threshold) {
            if (!is_string($operation) || $threshold < 0) {
                throw new \InvalidArgumentException("Invalid performance threshold for operation: {$operation}");
            }
        }

        foreach ($this->enabledChannels as $channel) {
            if (!is_string($channel) || empty(trim($channel))) {
                throw new \InvalidArgumentException("Invalid channel name: {$channel}");
            }
        }
    }
}
