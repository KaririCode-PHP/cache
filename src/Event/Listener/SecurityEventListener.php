<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\Listener;

use KaririCode\Cache\Contract\CacheEventListener;
use KaririCode\Cache\Contract\Structural\EventDispatcherAware;
use KaririCode\Cache\Event\DTO\IpStats;
use KaririCode\Cache\Event\DTO\SecurityContext;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * A functional security event listener for cache operations.
 *
 * This class focuses on essential security tasks: rate limiting, IP blocking,
 * and detecting basic suspicious patterns. It follows the SRP by delegating
 * data storage to dedicated DTOs.
 */
final class SecurityEventListener implements CacheEventListener, EventDispatcherAware
{
    /** @var array<string, IpStats> */
    private array $ipStatistics = [];

    /** @var array<string, int> */
    private array $blockedIPs = [];

    private EventDispatcherInterface $eventDispatcher;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $rateLimitMaxAttempts = 1000,
        private readonly int $rateLimitWindow = 300, // 5 minutes
        private readonly int $ipBlockDuration = 3600 // 1 hour
    ) {
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function hasEventDispatcher(): bool
    {
        return null !== $this->eventDispatcher;
    }

    public function onCacheHit(string $key, mixed $value): void
    {
        $this->handleEvent($key, $value);
    }

    public function onCacheMiss(string $key): void
    {
        $this->handleEvent($key);
    }

    public function onCacheSet(string $key, mixed $value): void
    {
        $this->handleEvent($key, $value);
    }

    public function onCacheDelete(string $key): void
    {
        $this->handleEvent($key);
    }

    public function onCacheClear(): void
    {
        $this->handleEvent('*');
    }

    /**
     * @return array<string, int>
     */
    public function getStats(): array
    {
        return [
            'tracked_ips' => count($this->ipStatistics),
            'blocked_ips' => count($this->blockedIPs),
        ];
    }

    /**
     * The main handler for all cache events to check for security issues.
     */
    private function handleEvent(string $key, mixed $value = null): void
    {
        $context = $this->buildSecurityContext();

        if ($this->isIpBlocked($context->ip)) {
            $this->log(LogLevel::WARNING, 'Blocked IP tried to access cache', ['key' => $key, 'ip' => $context->ip]);

            return;
        }

        if (!$this->isRateLimitOk($context->ip)) {
            $this->log(LogLevel::WARNING, 'Rate limit exceeded', ['key' => $key, 'ip' => $context->ip]);
            $this->blockIp($context->ip, 'Rate limit violation');

            return;
        }

        $this->detectSuspiciousKey($key, $context);
    }

    private function buildSecurityContext(): SecurityContext
    {
        return new SecurityContext(
            ip: $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            timestamp: time()
        );
    }

    private function isIpBlocked(string $ip): bool
    {
        if (!isset($this->blockedIPs[$ip])) {
            return false;
        }

        // Check if the block duration has expired.
        if (time() > $this->blockedIPs[$ip]) {
            unset($this->blockedIPs[$ip]);

            return false;
        }

        return true;
    }

    private function blockIp(string $ip, string $reason): void
    {
        $this->blockedIPs[$ip] = time() + $this->ipBlockDuration;
        $this->log(LogLevel::CRITICAL, 'IP has been blocked', ['ip' => $ip, 'reason' => $reason, 'duration_seconds' => $this->ipBlockDuration]);
    }

    private function isRateLimitOk(string $ip): bool
    {
        $now = time();

        if (!isset($this->ipStatistics[$ip])) {
            $this->ipStatistics[$ip] = new IpStats();
        }

        $ipStats = $this->ipStatistics[$ip];

        // 1. Clean up old timestamps outside the current window.
        $windowStart = $now - $this->rateLimitWindow;
        $ipStats->operationTimestamps = array_filter(
            $ipStats->operationTimestamps,
            fn ($timestamp) => $timestamp > $windowStart
        );

        // 2. Add current operation timestamp.
        $ipStats->operationTimestamps[] = $now;

        // 3. Check if the count exceeds the limit.
        return count($ipStats->operationTimestamps) <= $this->rateLimitMaxAttempts;
    }

    private function detectSuspiciousKey(string $key, SecurityContext $context): void
    {
        $suspiciousPatterns = ['/password/i', '/secret/i', '/token/i', '/api_key/i'];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $key)) {
                $this->log(LogLevel::WARNING, 'Potential sensitive data access attempt detected', ['key' => $key, 'ip' => $context->ip]);

                // Increase suspicious score for the IP
                if (isset($this->ipStatistics[$context->ip])) {
                    ++$this->ipStatistics[$context->ip]->suspiciousActivityCount;
                }

                return; // Log only once per event
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger->log($level, '[SecurityEventListener] ' . $message, $context);
    }
}
