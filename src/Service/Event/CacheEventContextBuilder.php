<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service\Event;

use KaririCode\Cache\Config\DTO\EventServiceConfig;
use KaririCode\Cache\Contract\Event\EventContextBuilder;

/**
 * Builds and sanitizes event contexts.
 */
final class CacheEventContextBuilder implements EventContextBuilder
{
    public function __construct(
        private readonly EventServiceConfig $config
    ) {
    }

    public function buildContext(string $key, string $eventType, array $additional = []): array
    {
        $context = [
            'component' => 'cache',
            'service' => 'event_service',
            'environment' => $_ENV['KARIRICODE_APP'] ?? 'unknown',
            'process_id' => getmypid(),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'timestamp' => microtime(true),
            'event_type' => $eventType,
            'cache_key' => $key,
            'key_length' => strlen($key),
            'key_hash' => hash('xxh3', $key),
        ];

        $merged = array_merge($context, $additional);

        return $this->sanitizeContext($merged);
    }

    public function sanitizeContext(array $context): array
    {
        // Redact sensitive keys
        foreach ($this->config->sensitiveKeys as $sensitiveKey) {
            if (isset($context[$sensitiveKey])) {
                $context[$sensitiveKey] = '[REDACTED]';
            }

            // Handle nested arrays/objects
            array_walk_recursive($context, function (&$value, $key) use ($sensitiveKey) {
                if ($key === $sensitiveKey) {
                    $value = '[REDACTED]';
                }
            });
        }

        // Check size limits
        $serialized = serialize($context);
        if (strlen($serialized) > $this->config->maxContextSize) {
            $context['_truncated'] = true;
            $context['_original_size'] = strlen($serialized);
        }

        return $context;
    }

    public function calculateValueSize(mixed $value): int
    {
        try {
            return match (true) {
                is_string($value) => strlen($value),
                is_array($value), is_object($value) => strlen(serialize($value)),
                default => strlen((string) $value),
            };
        } catch (\Throwable) {
            return 0;
        }
    }

    public function formatTtlHuman(?int $ttl): string
    {
        if (!$ttl) {
            return 'no_expiration';
        }

        return match (true) {
            $ttl < 60 => "{$ttl}s",
            $ttl < 3600 => round($ttl / 60, 1) . 'm',
            $ttl < 86400 => round($ttl / 3600, 1) . 'h',
            default => round($ttl / 86400, 1) . 'd',
        };
    }

    public function formatDurationMs(float $duration): float
    {
        return round($duration * 1000, 3);
    }

    public function calculateEfficiencyRatio(int $tagCount, float $affectedKeys): float
    {
        return $affectedKeys > 0 ? round($tagCount / $affectedKeys, 2) : 0;
    }
}
