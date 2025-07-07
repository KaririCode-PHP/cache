<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use KaririCode\Cache\Contract\CacheEventListener;
use Psr\Log\LoggerInterface;

/**
 * Security-focused event listener for audit trails and suspicious activity detection.
 */
final class SecurityEventListener implements CacheEventListener
{
    private array $auditLog = [];
    private array $suspiciousPatterns = [];
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->initializeSuspiciousPatterns();
    }

    public function onCacheHit(string $key, mixed $value): void
    {
        $this->recordAuditEvent('cache_hit', $key, [
            'value_type' => gettype($value),
            'timestamp' => time(),
            'ip' => $this->getCurrentUserIP(),
        ]);
    }

    public function onCacheMiss(string $key): void
    {
        $this->recordAuditEvent('cache_miss', $key, [
            'timestamp' => time(),
            'ip' => $this->getCurrentUserIP(),
        ]);

        $this->checkSuspiciousActivity($key, 'miss');
    }

    public function onCacheSet(string $key, mixed $value): void
    {
        $this->recordAuditEvent('cache_set', $key, [
            'value_type' => gettype($value),
            'value_size' => strlen(serialize($value)),
            'timestamp' => time(),
            'ip' => $this->getCurrentUserIP(),
        ]);

        $this->checkSuspiciousActivity($key, 'set', $value);
    }

    public function onCacheDelete(string $key): void
    {
        $this->recordAuditEvent('cache_delete', $key, [
            'timestamp' => time(),
            'ip' => $this->getCurrentUserIP(),
        ]);

        $this->checkSuspiciousActivity($key, 'delete');
    }

    public function onCacheClear(): void
    {
        $this->recordAuditEvent('cache_clear', 'all', [
            'timestamp' => time(),
            'ip' => $this->getCurrentUserIP(),
        ]);

        $this->logger->warning('Cache cleared - this may indicate suspicious activity', [
            'ip' => $this->getCurrentUserIP(),
            'timestamp' => time(),
        ]);
    }

    public function getAuditLog(): array
    {
        return $this->auditLog;
    }

    public function getSecurityReport(): array
    {
        return [
            'total_events' => count($this->auditLog),
            'unique_ips' => $this->getUniqueIPs(),
            'suspicious_activities' => $this->getSuspiciousActivities(),
            'top_accessed_keys' => $this->getTopAccessedKeys(),
            'recent_activities' => $this->getRecentActivities(50),
        ];
    }

    private function recordAuditEvent(string $event, string $key, array $context = []): void
    {
        $this->auditLog[] = [
            'event' => $event,
            'key' => $key,
            'context' => $context,
            'microtime' => microtime(true),
        ];

        // Keep audit log size manageable
        if (count($this->auditLog) > 10000) {
            $this->auditLog = array_slice($this->auditLog, -5000);
        }
    }

    private function checkSuspiciousActivity(string $key, string $operation, mixed $value = null): void
    {
        foreach ($this->suspiciousPatterns as $pattern => $config) {
            if ($this->matchesPattern($key, $operation, $value, $pattern, $config)) {
                $this->logger->warning('Suspicious cache activity detected', [
                    'pattern' => $pattern,
                    'key' => $key,
                    'operation' => $operation,
                    'ip' => $this->getCurrentUserIP(),
                ]);
            }
        }
    }

    private function matchesPattern(string $key, string $operation, mixed $value, string $pattern, array $config): bool
    {
        return match ($pattern) {
            'rapid_deletion' => $this->detectRapidDeletion($key),
            'large_value_injection' => $this->detectLargeValueInjection($value),
            'suspicious_key_pattern' => $this->detectSuspiciousKeyPattern($key),
            'bulk_operations' => $this->detectBulkOperations($operation),
            default => false
        };
    }

    private function detectRapidDeletion(string $key): bool
    {
        $recentDeletes = array_filter($this->auditLog, function ($event) {
            return 'cache_delete' === $event['event']
                && $event['microtime'] > microtime(true) - 60; // Last minute
        });

        return count($recentDeletes) > 10; // More than 10 deletes per minute
    }

    private function detectLargeValueInjection(mixed $value): bool
    {
        if (null === $value) {
            return false;
        }

        $size = strlen(serialize($value));

        return $size > 1024 * 1024; // > 1MB
    }

    private function detectSuspiciousKeyPattern(string $key): bool
    {
        $suspiciousPatterns = [
            '/\.\.\/',  // Path traversal
            '/script/i', // Script injection attempts
            '/admin/i',  // Admin area access
            '/\.php$/i', // PHP file extensions
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    private function detectBulkOperations(string $operation): bool
    {
        $recentSameOps = array_filter($this->auditLog, function ($event) use ($operation) {
            return $event['event'] === "cache_{$operation}"
                && $event['microtime'] > microtime(true) - 10; // Last 10 seconds
        });

        return count($recentSameOps) > 50; // More than 50 same operations in 10 seconds
    }

    private function initializeSuspiciousPatterns(): void
    {
        $this->suspiciousPatterns = [
            'rapid_deletion' => ['threshold' => 10, 'window' => 60],
            'large_value_injection' => ['max_size' => 1024 * 1024],
            'suspicious_key_pattern' => ['patterns' => ['/\.\.\//', '/script/i', '/admin/i']],
            'bulk_operations' => ['threshold' => 50, 'window' => 10],
        ];
    }

    private function getCurrentUserIP(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown';
    }

    private function getUniqueIPs(): array
    {
        $ips = array_map(fn ($event) => $event['context']['ip'] ?? 'unknown', $this->auditLog);

        return array_unique($ips);
    }

    private function getSuspiciousActivities(): array
    {
        return array_filter($this->auditLog, function ($event) {
            // Filter events that might be suspicious based on various criteria
            $key = $event['key'];

            return $this->detectSuspiciousKeyPattern($key)
                   || ($event['context']['value_size'] ?? 0) > 1024 * 1024;
        });
    }

    private function getTopAccessedKeys(int $limit = 10): array
    {
        $keyAccess = [];
        foreach ($this->auditLog as $event) {
            $key = $event['key'];
            $keyAccess[$key] = ($keyAccess[$key] ?? 0) + 1;
        }

        arsort($keyAccess);

        return array_slice($keyAccess, 0, $limit, true);
    }

    private function getRecentActivities(int $limit = 50): array
    {
        return array_slice($this->auditLog, -$limit);
    }
}
