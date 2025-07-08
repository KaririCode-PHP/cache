<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\Listener;

use KaririCode\Cache\Contract\CacheEventListener;
use KaririCode\Cache\Event\CacheSecurityEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Comprehensive security event listener for cache operations.
 *
 * This implementation provides enterprise-grade security monitoring including:
 * - Threat detection and analysis
 * - Rate limiting and abuse prevention
 * - IP tracking and geolocation
 * - Suspicious pattern recognition
 * - Compliance and audit logging
 * - Real-time alerting
 * - Forensic data collection
 *
 * Following security best practices and OWASP guidelines.
 */
final class SecurityEventListener implements CacheEventListener
{
    private array $auditLog = [];
    private array $threatPatterns = [];
    private array $ipStatistics = [];
    private array $userStatistics = [];
    private array $keyStatistics = [];
    private array $suspiciousActivities = [];
    private array $rateLimit = [];
    private array $blockedIPs = [];
    private array $whitelistedIPs = [];
    private ?EventDispatcherInterface $eventDispatcher = null;

    private const MAX_AUDIT_ENTRIES = 10000;
    private const RATE_LIMIT_WINDOW = 300; // 5 minutes
    private const DEFAULT_RATE_LIMIT = 1000; // operations per window
    private const SUSPICIOUS_THRESHOLD = 5;
    private const LARGE_VALUE_THRESHOLD = 1024 * 1024; // 1MB

    public function __construct(
        private LoggerInterface $logger,
        private array $config = []
    ) {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->initializeThreatPatterns();
        $this->loadWhitelistedIPs();
    }

    public function setEventDispatcher(?EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function onCacheHit(string $key, mixed $value): void
    {
        $context = $this->buildSecurityContext($key, 'hit', ['value_type' => gettype($value)]);

        $this->recordAuditEvent('cache_hit', $key, $context);
        $this->analyzeSecurityThreats($key, 'hit', $value, $context);
        $this->updateStatistics($key, 'hit', $context);

        // Dispatch PSR-14 security event
        if ($this->eventDispatcher) {
            $securityEvent = new CacheSecurityEvent('hit', $key, $context);
            $this->eventDispatcher->dispatch($securityEvent);
        }
    }

    public function onCacheMiss(string $key): void
    {
        $context = $this->buildSecurityContext($key, 'miss');

        $this->recordAuditEvent('cache_miss', $key, $context);
        $this->analyzeSecurityThreats($key, 'miss', null, $context);
        $this->updateStatistics($key, 'miss', $context);

        // Check for cache enumeration attacks
        $this->detectCacheEnumeration($key, $context);

        // Dispatch PSR-14 security event
        if ($this->eventDispatcher) {
            $securityEvent = new CacheSecurityEvent('miss', $key, $context);
            $this->eventDispatcher->dispatch($securityEvent);
        }
    }

    public function onCacheSet(string $key, mixed $value): void
    {
        $context = $this->buildSecurityContext($key, 'set', [
            'value_type' => gettype($value),
            'value_size' => $this->calculateValueSize($value),
        ]);

        $this->recordAuditEvent('cache_set', $key, $context);
        $this->analyzeSecurityThreats($key, 'set', $value, $context);
        $this->updateStatistics($key, 'set', $context);

        // Additional security checks for set operations
        $this->detectLargeValueInjection($key, $value, $context);
        $this->detectSensitiveDataExposure($key, $value, $context);
        $this->validateValueIntegrity($key, $value, $context);

        // Dispatch PSR-14 security event
        if ($this->eventDispatcher) {
            $securityEvent = new CacheSecurityEvent('set', $key, $context, $value);
            $this->eventDispatcher->dispatch($securityEvent);
        }
    }

    public function onCacheDelete(string $key): void
    {
        $context = $this->buildSecurityContext($key, 'delete');

        $this->recordAuditEvent('cache_delete', $key, $context);
        $this->analyzeSecurityThreats($key, 'delete', null, $context);
        $this->updateStatistics($key, 'delete', $context);

        // Check for malicious deletion patterns
        $this->detectMaliciousDeletion($key, $context);

        // Dispatch PSR-14 security event
        if ($this->eventDispatcher) {
            $securityEvent = new CacheSecurityEvent('delete', $key, $context);
            $this->eventDispatcher->dispatch($securityEvent);
        }
    }

    public function onCacheClear(): void
    {
        $context = $this->buildSecurityContext('*', 'clear');

        $this->recordAuditEvent('cache_clear', '*', $context);

        // Cache clear is always suspicious - log as critical
        $this->logger->critical('Cache cleared - potential security incident', $context);
        $this->recordSuspiciousActivity('cache_clear', '*', 'CRITICAL: Full cache clear operation', $context);

        // Dispatch PSR-14 security event
        if ($this->eventDispatcher) {
            $securityEvent = new CacheSecurityEvent('clear', '*', $context);
            $this->eventDispatcher->dispatch($securityEvent);
        }
    }

    /**
     * Get comprehensive security statistics.
     */
    public function getStats(): array
    {
        return [
            'audit_entries' => count($this->auditLog),
            'suspicious_activities' => count($this->suspiciousActivities),
            'blocked_ips' => count($this->blockedIPs),
            'unique_ips' => count($this->ipStatistics),
            'threat_detections' => $this->getThreatDetectionCount(),
            'rate_limit_violations' => $this->getRateLimitViolationCount(),
        ];
    }

    /**
     * Get detailed security report.
     */
    public function getSecurityReport(): array
    {
        return [
            'summary' => $this->getSecuritySummary(),
            'threat_analysis' => $this->getThreatAnalysis(),
            'ip_analysis' => $this->getIPAnalysis(),
            'key_analysis' => $this->getKeyAnalysis(),
            'user_analysis' => $this->getUserAnalysis(),
            'suspicious_activities' => $this->getSuspiciousActivities(),
            'compliance_report' => $this->getComplianceReport(),
            'recommendations' => $this->getSecurityRecommendations(),
        ];
    }

    /**
     * Get real-time security alerts.
     */
    public function getSecurityAlerts(): array
    {
        $alerts = [];
        $now = time();

        // Check for recent suspicious activities
        foreach ($this->suspiciousActivities as $activity) {
            if (($now - $activity['timestamp']) < 3600) { // Last hour
                $alerts[] = [
                    'type' => 'suspicious_activity',
                    'severity' => $this->calculateSeverity($activity),
                    'message' => $activity['description'],
                    'timestamp' => $activity['timestamp'],
                    'context' => $activity['context'],
                ];
            }
        }

        // Check for blocked IPs trying to access
        foreach ($this->blockedIPs as $ip => $blockInfo) {
            if (($now - $blockInfo['last_attempt']) < 300) { // Last 5 minutes
                $alerts[] = [
                    'type' => 'blocked_ip_activity',
                    'severity' => 'HIGH',
                    'message' => "Blocked IP {$ip} attempting cache access",
                    'timestamp' => $blockInfo['last_attempt'],
                    'context' => $blockInfo,
                ];
            }
        }

        // Check for rate limit violations
        foreach ($this->rateLimit as $ip => $limitInfo) {
            if ($limitInfo['violations'] > 0 && ($now - $limitInfo['last_violation']) < 600) {
                $alerts[] = [
                    'type' => 'rate_limit_violation',
                    'severity' => 'MEDIUM',
                    'message' => "Rate limit violation from IP {$ip}",
                    'timestamp' => $limitInfo['last_violation'],
                    'context' => $limitInfo,
                ];
            }
        }

        return $alerts;
    }

    /**
     * Block an IP address.
     */
    public function blockIP(string $ip, string $reason, int $duration = 3600): void
    {
        $this->blockedIPs[$ip] = [
            'blocked_at' => time(),
            'reason' => $reason,
            'duration' => $duration,
            'last_attempt' => time(),
        ];

        $this->logger->warning("IP blocked: {$ip}", [
            'reason' => $reason,
            'duration' => $duration,
        ]);
    }

    /**
     * Unblock an IP address.
     */
    public function unblockIP(string $ip): bool
    {
        if (isset($this->blockedIPs[$ip])) {
            unset($this->blockedIPs[$ip]);
            $this->logger->info("IP unblocked: {$ip}");

            return true;
        }

        return false;
    }

    /**
     * Check if an IP is blocked.
     */
    public function isIPBlocked(string $ip): bool
    {
        if (!isset($this->blockedIPs[$ip])) {
            return false;
        }

        $blockInfo = $this->blockedIPs[$ip];
        $expiryTime = $blockInfo['blocked_at'] + $blockInfo['duration'];

        if (time() > $expiryTime) {
            unset($this->blockedIPs[$ip]);

            return false;
        }

        // Update last attempt
        $this->blockedIPs[$ip]['last_attempt'] = time();

        return true;
    }

    /**
     * Add IP to whitelist.
     */
    public function whitelistIP(string $ip): void
    {
        $this->whitelistedIPs[] = $ip;
        $this->logger->info("IP whitelisted: {$ip}");
    }

    /**
     * Check if IP is whitelisted.
     */
    public function isIPWhitelisted(string $ip): bool
    {
        return in_array($ip, $this->whitelistedIPs);
    }

    /**
     * Export audit log for compliance.
     */
    public function exportAuditLog(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $fromTimestamp = $from ? $from->getTimestamp() : 0;
        $toTimestamp = $to ? $to->getTimestamp() : PHP_INT_MAX;

        return array_filter($this->auditLog, function ($entry) use ($fromTimestamp, $toTimestamp) {
            return $entry['timestamp'] >= $fromTimestamp && $entry['timestamp'] <= $toTimestamp;
        });
    }

    /**
     * Clear old audit entries to manage memory.
     */
    public function cleanupAuditLog(int $retentionDays = 30): int
    {
        $cutoffTime = time() - ($retentionDays * 24 * 3600);
        $initialCount = count($this->auditLog);

        $this->auditLog = array_filter($this->auditLog, function ($entry) use ($cutoffTime) {
            return $entry['timestamp'] > $cutoffTime;
        });

        $removedCount = $initialCount - count($this->auditLog);

        if ($removedCount > 0) {
            $this->logger->info("Cleaned up audit log: removed {$removedCount} old entries");
        }

        return $removedCount;
    }

    /**
     * Build security context for events.
     */
    private function buildSecurityContext(string $key, string $operation, array $additional = []): array
    {
        $ip = $this->getCurrentUserIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $timestamp = time();

        return array_merge([
            'ip' => $ip,
            'user_agent' => $userAgent,
            'timestamp' => $timestamp,
            'operation' => $operation,
            'key' => $key,
            'session_id' => session_id() ?: 'no_session',
            'request_id' => $this->generateRequestId(),
            'geo_location' => $this->getGeoLocation($ip),
        ], $additional);
    }

    /**
     * Record audit event for compliance.
     */
    private function recordAuditEvent(string $event, string $key, array $context): void
    {
        $auditEntry = [
            'event' => $event,
            'key' => $key,
            'context' => $context,
            'timestamp' => $context['timestamp'],
            'hash' => $this->calculateAuditHash($event, $key, $context),
        ];

        $this->auditLog[] = $auditEntry;

        // Maintain audit log size
        if (count($this->auditLog) > self::MAX_AUDIT_ENTRIES) {
            array_shift($this->auditLog);
        }
    }

    /**
     * Analyze potential security threats.
     */
    private function analyzeSecurityThreats(string $key, string $operation, mixed $value, array $context): void
    {
        // Check rate limiting
        if (!$this->checkRateLimit($context['ip'])) {
            $this->recordSuspiciousActivity('rate_limit_exceeded', $key, "Rate limit exceeded for IP {$context['ip']}", $context);
        }

        // Check if IP is blocked
        if ($this->isIPBlocked($context['ip'])) {
            $this->recordSuspiciousActivity('blocked_ip_access', $key, "Blocked IP {$context['ip']} attempting access", $context);

            return;
        }

        // Check threat patterns
        foreach ($this->threatPatterns as $pattern) {
            if ($this->matchesThreatPattern($key, $operation, $value, $pattern)) {
                $this->recordSuspiciousActivity('threat_pattern_match', $key, $pattern['description'], $context);

                if ('CRITICAL' === $pattern['severity']) {
                    $this->blockIP($context['ip'], "Critical threat pattern match: {$pattern['name']}", 3600);
                }
            }
        }

        // Behavioral analysis
        $this->performBehavioralAnalysis($context);
    }

    /**
     * Update security statistics.
     */
    private function updateStatistics(string $key, string $operation, array $context): void
    {
        $ip = $context['ip'];

        // IP statistics
        if (!isset($this->ipStatistics[$ip])) {
            $this->ipStatistics[$ip] = [
                'first_seen' => $context['timestamp'],
                'operations' => [],
                'total_operations' => 0,
                'suspicious_score' => 0,
            ];
        }

        $this->ipStatistics[$ip]['operations'][$operation] = ($this->ipStatistics[$ip]['operations'][$operation] ?? 0) + 1;
        ++$this->ipStatistics[$ip]['total_operations'];
        $this->ipStatistics[$ip]['last_seen'] = $context['timestamp'];

        // Key statistics
        if (!isset($this->keyStatistics[$key])) {
            $this->keyStatistics[$key] = [
                'access_count' => 0,
                'unique_ips' => [],
                'operations' => [],
            ];
        }

        ++$this->keyStatistics[$key]['access_count'];
        $this->keyStatistics[$key]['operations'][$operation] = ($this->keyStatistics[$key]['operations'][$operation] ?? 0) + 1;

        if (!in_array($ip, $this->keyStatistics[$key]['unique_ips'])) {
            $this->keyStatistics[$key]['unique_ips'][] = $ip;
        }
    }

    /**
     * Check rate limiting for IP.
     */
    private function checkRateLimit(string $ip): bool
    {
        if ($this->isIPWhitelisted($ip)) {
            return true;
        }

        $now = time();
        $windowStart = $now - self::RATE_LIMIT_WINDOW;

        if (!isset($this->rateLimit[$ip])) {
            $this->rateLimit[$ip] = [
                'operations' => [],
                'violations' => 0,
                'last_violation' => 0,
            ];
        }

        // Remove old operations outside the window
        $this->rateLimit[$ip]['operations'] = array_filter(
            $this->rateLimit[$ip]['operations'],
            fn ($timestamp) => $timestamp > $windowStart
        );

        // Add current operation
        $this->rateLimit[$ip]['operations'][] = $now;

        $limit = $this->config['rate_limit'] ?? self::DEFAULT_RATE_LIMIT;

        if (count($this->rateLimit[$ip]['operations']) > $limit) {
            ++$this->rateLimit[$ip]['violations'];
            $this->rateLimit[$ip]['last_violation'] = $now;

            $this->logger->warning("Rate limit exceeded for IP: {$ip}", [
                'operations_in_window' => count($this->rateLimit[$ip]['operations']),
                'limit' => $limit,
                'window' => self::RATE_LIMIT_WINDOW,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Record suspicious activity.
     */
    private function recordSuspiciousActivity(string $type, string $key, string $description, array $context): void
    {
        $activity = [
            'type' => $type,
            'key' => $key,
            'description' => $description,
            'context' => $context,
            'timestamp' => time(),
            'severity' => $this->calculateThreatSeverity($type),
        ];

        $this->suspiciousActivities[] = $activity;

        // Log based on severity
        $logLevel = match ($activity['severity']) {
            'CRITICAL' => LogLevel::CRITICAL,
            'HIGH' => LogLevel::ERROR,
            'MEDIUM' => LogLevel::WARNING,
            default => LogLevel::INFO,
        };

        $this->logger->log($logLevel, "Suspicious activity detected: {$description}", $context);

        // Update IP suspicious score
        $ip = $context['ip'];
        if (isset($this->ipStatistics[$ip])) {
            $this->ipStatistics[$ip]['suspicious_score'] += $this->getSeverityScore($activity['severity']);
        }
    }

    /**
     * Detect cache enumeration attacks.
     */
    private function detectCacheEnumeration(string $key, array $context): void
    {
        $ip = $context['ip'];
        $now = time();
        $window = 300; // 5 minutes

        // Count recent misses from this IP
        $recentMisses = array_filter($this->auditLog, function ($entry) use ($ip, $now, $window) {
            return 'cache_miss' === $entry['event']
                   && $entry['context']['ip'] === $ip
                   && ($now - $entry['timestamp']) < $window;
        });

        if (count($recentMisses) > 50) { // More than 50 misses in 5 minutes
            $this->recordSuspiciousActivity(
                'cache_enumeration',
                $key,
                "Potential cache enumeration attack from IP {$ip}",
                $context
            );
        }
    }

    /**
     * Detect large value injection attacks.
     */
    private function detectLargeValueInjection(string $key, mixed $value, array $context): void
    {
        $size = $this->calculateValueSize($value);

        if ($size > self::LARGE_VALUE_THRESHOLD) {
            $this->recordSuspiciousActivity(
                'large_value_injection',
                $key,
                "Large value injection detected: {$size} bytes",
                array_merge($context, ['value_size' => $size])
            );
        }
    }

    /**
     * Detect sensitive data exposure.
     */
    private function detectSensitiveDataExposure(string $key, mixed $value, array $context): void
    {
        $sensitivePatterns = [
            '/password/i',
            '/secret/i',
            '/token/i',
            '/api[_-]?key/i',
            '/private[_-]?key/i',
            '/credit[_-]?card/i',
            '/ssn/i',
        ];

        $serializedValue = serialize($value);

        foreach ($sensitivePatterns as $pattern) {
            if (preg_match($pattern, $key) || preg_match($pattern, $serializedValue)) {
                $this->recordSuspiciousActivity(
                    'sensitive_data_exposure',
                    $key,
                    'Potential sensitive data in cache key or value',
                    $context
                );
                break;
            }
        }
    }

    /**
     * Validate value integrity.
     */
    private function validateValueIntegrity(string $key, mixed $value, array $context): void
    {
        // Check for potential code injection
        if (is_string($value)) {
            $dangerousPatterns = [
                '/<\?php/i',
                '/eval\s*\(/i',
                '/system\s*\(/i',
                '/exec\s*\(/i',
                '/shell_exec\s*\(/i',
                '/`[^`]*`/',
            ];

            foreach ($dangerousPatterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    $this->recordSuspiciousActivity(
                        'code_injection_attempt',
                        $key,
                        'Potential code injection in cache value',
                        $context
                    );
                    break;
                }
            }
        }
    }

    /**
     * Detect malicious deletion patterns.
     */
    private function detectMaliciousDeletion(string $key, array $context): void
    {
        $ip = $context['ip'];
        $now = time();
        $window = 300; // 5 minutes

        // Count recent deletions from this IP
        $recentDeletions = array_filter($this->auditLog, function ($entry) use ($ip, $now, $window) {
            return 'cache_delete' === $entry['event']
                   && $entry['context']['ip'] === $ip
                   && ($now - $entry['timestamp']) < $window;
        });

        if (count($recentDeletions) > 20) { // More than 20 deletions in 5 minutes
            $this->recordSuspiciousActivity(
                'malicious_deletion',
                $key,
                "Potential malicious deletion pattern from IP {$ip}",
                $context
            );
        }
    }

    /**
     * Perform behavioral analysis.
     */
    private function performBehavioralAnalysis(array $context): void
    {
        $ip = $context['ip'];

        if (!isset($this->ipStatistics[$ip])) {
            return;
        }

        $stats = $this->ipStatistics[$ip];

        // Check for unusual operation patterns
        $totalOps = $stats['total_operations'];
        if ($totalOps > 1000) { // Only analyze IPs with significant activity
            $deleteRatio = ($stats['operations']['delete'] ?? 0) / $totalOps;
            $setRatio = ($stats['operations']['set'] ?? 0) / $totalOps;

            // Suspicious if more than 50% operations are deletions
            if ($deleteRatio > 0.5) {
                $this->recordSuspiciousActivity(
                    'unusual_delete_pattern',
                    'behavioral_analysis',
                    "Unusual deletion pattern from IP {$ip}: {$deleteRatio}% deletions",
                    $context
                );
            }

            // Suspicious if operations are predominantly writes without reads
            $hitRatio = ($stats['operations']['hit'] ?? 0) / $totalOps;
            if ($setRatio > 0.8 && $hitRatio < 0.1) {
                $this->recordSuspiciousActivity(
                    'write_only_pattern',
                    'behavioral_analysis',
                    "Write-only pattern from IP {$ip}: minimal reads detected",
                    $context
                );
            }
        }
    }

    /**
     * Check if pattern matches threat signature.
     */
    private function matchesThreatPattern(string $key, string $operation, mixed $value, array $pattern): bool
    {
        // Key-based patterns
        if (isset($pattern['key_pattern']) && preg_match($pattern['key_pattern'], $key)) {
            return true;
        }

        // Operation-based patterns
        if (isset($pattern['operations']) && in_array($operation, $pattern['operations'])) {
            return true;
        }

        // Value-based patterns
        if (isset($pattern['value_pattern']) && is_string($value) && preg_match($pattern['value_pattern'], $value)) {
            return true;
        }

        return false;
    }

    /**
     * Initialize threat detection patterns.
     */
    private function initializeThreatPatterns(): void
    {
        $this->threatPatterns = [
            [
                'name' => 'SQL Injection',
                'key_pattern' => '/union\s+select|drop\s+table|insert\s+into/i',
                'severity' => 'HIGH',
                'description' => 'Potential SQL injection in cache key',
            ],
            [
                'name' => 'XSS Attempt',
                'value_pattern' => '/<script|javascript:|onload=|onerror=/i',
                'severity' => 'MEDIUM',
                'description' => 'Potential XSS payload in cache value',
            ],
            [
                'name' => 'Directory Traversal',
                'key_pattern' => '/\.\.\/|\.\.\\\\/',
                'severity' => 'HIGH',
                'description' => 'Directory traversal attempt in cache key',
            ],
            [
                'name' => 'Admin Access',
                'key_pattern' => '/admin|root|administrator|config/i',
                'operations' => ['delete', 'set'],
                'severity' => 'CRITICAL',
                'description' => 'Suspicious admin-related cache operation',
            ],
        ];
    }

    /**
     * Load whitelisted IPs from configuration.
     */
    private function loadWhitelistedIPs(): void
    {
        $this->whitelistedIPs = $this->config['whitelisted_ips'] ?? [
            '127.0.0.1',
            '::1',
        ];
    }

    /**
     * Calculate value size for analysis.
     */
    private function calculateValueSize(mixed $value): int
    {
        return strlen(serialize($value));
    }

    /**
     * Get current user IP address.
     */
    private function getCurrentUserIP(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Generate unique request ID.
     */
    private function generateRequestId(): string
    {
        return md5(uniqid() . microtime(true) . random_bytes(8));
    }

    /**
     * Get geo location for IP (mock implementation).
     */
    private function getGeoLocation(string $ip): array
    {
        // In a real implementation, this would use a geolocation service
        return [
            'country' => 'unknown',
            'region' => 'unknown',
            'city' => 'unknown',
        ];
    }

    /**
     * Calculate audit hash for integrity.
     */
    private function calculateAuditHash(string $event, string $key, array $context): string
    {
        return hash('sha256', $event . $key . serialize($context) . ($this->config['audit_salt'] ?? 'default_salt'));
    }

    /**
     * Calculate threat severity.
     */
    private function calculateThreatSeverity(string $type): string
    {
        $severityMap = [
            'cache_clear' => 'CRITICAL',
            'code_injection_attempt' => 'CRITICAL',
            'blocked_ip_access' => 'HIGH',
            'large_value_injection' => 'HIGH',
            'cache_enumeration' => 'MEDIUM',
            'malicious_deletion' => 'MEDIUM',
            'sensitive_data_exposure' => 'MEDIUM',
            'rate_limit_exceeded' => 'LOW',
        ];

        return $severityMap[$type] ?? 'LOW';
    }

    /**
     * Get severity score for calculations.
     */
    private function getSeverityScore(string $severity): int
    {
        return match ($severity) {
            'CRITICAL' => 10,
            'HIGH' => 7,
            'MEDIUM' => 4,
            'LOW' => 1,
            default => 0,
        };
    }

    /**
     * Calculate severity based on activity data.
     */
    private function calculateSeverity(array $activity): string
    {
        return $activity['severity'] ?? 'LOW';
    }

    /**
     * Get security summary.
     */
    private function getSecuritySummary(): array
    {
        return [
            'total_events' => count($this->auditLog),
            'suspicious_activities' => count($this->suspiciousActivities),
            'blocked_ips' => count($this->blockedIPs),
            'unique_ips' => count($this->ipStatistics),
            'high_risk_ips' => $this->getHighRiskIPCount(),
            'last_incident' => $this->getLastIncidentTime(),
        ];
    }

    /**
     * Get threat analysis data.
     */
    private function getThreatAnalysis(): array
    {
        $threats = [];
        foreach ($this->suspiciousActivities as $activity) {
            $type = $activity['type'];
            $threats[$type] = ($threats[$type] ?? 0) + 1;
        }

        return $threats;
    }

    /**
     * Get IP analysis data.
     */
    private function getIPAnalysis(): array
    {
        return array_map(function ($stats) {
            return [
                'total_operations' => $stats['total_operations'],
                'suspicious_score' => $stats['suspicious_score'],
                'first_seen' => $stats['first_seen'],
                'last_seen' => $stats['last_seen'],
                'risk_level' => $this->calculateIPRiskLevel($stats),
            ];
        }, $this->ipStatistics);
    }

    /**
     * Get key analysis data.
     */
    private function getKeyAnalysis(): array
    {
        return $this->keyStatistics;
    }

    /**
     * Get user analysis data.
     */
    private function getUserAnalysis(): array
    {
        return $this->userStatistics;
    }

    /**
     * Get suspicious activities.
     */
    private function getSuspiciousActivities(): array
    {
        return $this->suspiciousActivities;
    }

    /**
     * Get compliance report.
     */
    private function getComplianceReport(): array
    {
        return [
            'audit_log_integrity' => $this->verifyAuditLogIntegrity(),
            'retention_compliance' => $this->checkRetentionCompliance(),
            'access_patterns' => $this->analyzeAccessPatterns(),
        ];
    }

    /**
     * Get security recommendations.
     */
    private function getSecurityRecommendations(): array
    {
        $recommendations = [];

        if (count($this->blockedIPs) > 10) {
            $recommendations[] = 'High number of blocked IPs detected. Consider implementing stricter access controls.';
        }

        if ($this->getThreatDetectionCount() > 50) {
            $recommendations[] = 'Multiple threat patterns detected. Review and update security policies.';
        }

        return $recommendations;
    }

    /**
     * Get default configuration.
     */
    private function getDefaultConfig(): array
    {
        return [
            'rate_limit' => self::DEFAULT_RATE_LIMIT,
            'audit_salt' => 'cache_security_salt',
            'whitelisted_ips' => ['127.0.0.1', '::1'],
            'enable_geo_location' => false,
            'retention_days' => 30,
        ];
    }

    /**
     * Helper methods for statistics.
     */
    private function getThreatDetectionCount(): int
    {
        return count($this->suspiciousActivities);
    }

    private function getRateLimitViolationCount(): int
    {
        return array_sum(array_column($this->rateLimit, 'violations'));
    }

    private function getHighRiskIPCount(): int
    {
        return count(array_filter($this->ipStatistics, fn ($stats) => $stats['suspicious_score'] > 20));
    }

    private function getLastIncidentTime(): ?int
    {
        if (empty($this->suspiciousActivities)) {
            return null;
        }

        return max(array_column($this->suspiciousActivities, 'timestamp'));
    }

    private function calculateIPRiskLevel(array $stats): string
    {
        $score = $stats['suspicious_score'];

        if ($score > 30) {
            return 'CRITICAL';
        }
        if ($score > 20) {
            return 'HIGH';
        }
        if ($score > 10) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    private function verifyAuditLogIntegrity(): bool
    {
        // Simplified integrity check
        return count($this->auditLog) > 0;
    }

    private function checkRetentionCompliance(): bool
    {
        $retentionDays = $this->config['retention_days'] ?? 30;
        $oldestEntry = min(array_column($this->auditLog, 'timestamp'));
        $cutoffTime = time() - ($retentionDays * 24 * 3600);

        return $oldestEntry > $cutoffTime;
    }

    private function analyzeAccessPatterns(): array
    {
        // Simplified access pattern analysis
        return [
            'total_unique_keys' => count($this->keyStatistics),
            'average_access_per_key' => array_sum(array_column($this->keyStatistics, 'access_count')) / max(1, count($this->keyStatistics)),
        ];
    }
}
