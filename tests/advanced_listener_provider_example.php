<?php

declare(strict_types=1);

/**
 * Comprehensive examples for CacheListenerProvider and SecurityEventListener.
 *
 * Demonstrates advanced features and enterprise-grade security monitoring.
 */
require __DIR__ . '/../vendor/autoload.php';

use KaririCode\Cache\CacheBuilder;
use KaririCode\Cache\Event\CacheDeleteEvent;
use KaririCode\Cache\Event\CacheEventFactory;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Event\CacheListenerProvider;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Event\CacheSetEvent;
use KaririCode\Cache\Event\SecurityEventListener;

// Example 1: Advanced CacheListenerProvider Features
function advancedListenerProviderExample()
{
    echo "=== Advanced CacheListenerProvider Features ===\n";

    // Create enhanced listener provider
    $provider = new CacheListenerProvider(true, 500); // Caching enabled, max 500 cache entries

    // 1. Priority-based listeners
    $provider->addListener(CacheHitEvent::class, function ($event) {
        echo "High Priority: Cache hit for {$event->getKey()}\n";
    }, 100); // High priority

    $provider->addListener(CacheHitEvent::class, function ($event) {
        echo "Low Priority: Cache hit for {$event->getKey()}\n";
    }, 10); // Low priority

    // 2. Wildcard listeners for pattern matching
    $provider->addWildcardListener('*Cache*Event', function ($event) {
        echo "Wildcard: Event {$event->getKey()} matched cache pattern\n";
    }, 50);

    // 3. Regex listeners for complex patterns
    $provider->addRegexListener('/^user:\d+$/', function ($event) {
        echo "Regex: User-specific cache event for {$event->getKey()}\n";
    });

    // 4. Conditional listeners
    $provider->addConditionalListener(
        CacheSetEvent::class,
        function ($event) {
            echo "Conditional: Large value detected for {$event->getKey()}\n";
        },
        function ($event) {
            return $event->getValueSize() > 1024; // Only for values > 1KB
        },
        75
    );

    // 5. Listener groups for batch management
    $provider->createListenerGroup('debug_group', [
        CacheHitEvent::class => [
            ['callable' => function ($event) { echo "Debug Hit: {$event->getKey()}\n"; }, 'priority' => 20],
        ],
        CacheMissEvent::class => [
            ['callable' => function ($event) { echo "Debug Miss: {$event->getKey()}\n"; }, 'priority' => 20],
        ],
    ]);

    // Test the provider
    $dispatcher = CacheEventFactory::createEventDispatcher($provider);

    // Simulate events
    $hitEvent = new CacheHitEvent('user:123', ['name' => 'John'], 0.001);
    $setEvent = new CacheSetEvent('large:data', str_repeat('x', 2048)); // Large value

    echo "\nTesting events:\n";
    $dispatcher->dispatch($hitEvent);
    $dispatcher->dispatch($setEvent);

    // Show statistics
    $stats = $provider->getStatistics();
    echo "\nProvider Statistics:\n";
    echo "- Total Listeners: {$stats['total_listeners']}\n";
    echo "- Cache Hits: {$stats['cache_hits']}\n";
    echo "- Cache Misses: {$stats['cache_misses']}\n";
    echo "- Pattern Matches: {$stats['pattern_matches']}\n";

    // Toggle listener group
    echo "\nDisabling debug group...\n";
    $provider->toggleListenerGroup('debug_group', false);

    echo "\n";
}

// Example 2: Comprehensive Security Monitoring
function comprehensiveSecurityExample()
{
    echo "=== Comprehensive Security Monitoring ===\n";

    // Create security logger
    $logger = new class implements Psr\Log\LoggerInterface {
        private array $logs = [];

        public function emergency($message, array $context = []): void
        {
            $this->log('emergency', $message, $context);
        }

        public function alert($message, array $context = []): void
        {
            $this->log('alert', $message, $context);
        }

        public function critical($message, array $context = []): void
        {
            $this->log('critical', $message, $context);
        }

        public function error($message, array $context = []): void
        {
            $this->log('error', $message, $context);
        }

        public function warning($message, array $context = []): void
        {
            $this->log('warning', $message, $context);
        }

        public function notice($message, array $context = []): void
        {
            $this->log('notice', $message, $context);
        }

        public function info($message, array $context = []): void
        {
            $this->log('info', $message, $context);
        }

        public function debug($message, array $context = []): void
        {
            $this->log('debug', $message, $context);
        }

        public function log($level, $message, array $context = []): void
        {
            $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
            echo "[{$level}] {$message}\n";
        }

        public function getLogs(): array
        {
            return $this->logs;
        }
    };

    // Create security event listener with custom configuration
    $securityConfig = [
        'rate_limit' => 100, // 100 operations per 5 minutes
        'whitelisted_ips' => ['127.0.0.1', '192.168.1.100'],
        'audit_salt' => 'my_secure_salt_2024',
        'retention_days' => 90,
    ];

    $security = new SecurityEventListener($logger, $securityConfig);

    // Create cache with security monitoring
    $eventSystem = CacheEventFactory::createCompleteEventSystem();
    $security->setEventDispatcher($eventSystem['eventDispatcher']);

    $cache = CacheBuilder::create()
        ->withFilesystem()
        ->withEventListener($security)
        ->build();

    echo "Testing security scenarios:\n";

    // 1. Normal operations
    echo "\n1. Normal Operations:\n";
    $cache->set('user:session:123', ['user_id' => 123, 'login_time' => time()]);
    $cache->get('user:session:123');

    // 2. Suspicious patterns
    echo "\n2. Suspicious Patterns:\n";

    // Simulate potential SQL injection
    $cache->set('user:1\' OR 1=1 --', 'malicious_data');

    // Simulate XSS attempt
    $cache->set('comment:456', '<script>alert("xss")</script>');

    // Simulate directory traversal
    $cache->get('config:../../etc/passwd');

    // 3. Large value injection
    echo "\n3. Large Value Injection:\n";
    $largePayload = str_repeat('PAYLOAD', 50000); // ~350KB
    $cache->set('upload:file', $largePayload);

    // 4. Sensitive data patterns
    echo "\n4. Sensitive Data Detection:\n";
    $cache->set('user:password:hash', 'secret_password_123');
    $cache->set('api_key:service', 'sk-1234567890abcdef');

    // 5. Admin operations (highly suspicious)
    echo "\n5. Admin Operations:\n";
    $cache->delete('admin:config:database');
    $cache->set('root:access:token', 'admin_token_123');

    // 6. Mass operations (potential attack)
    echo "\n6. Mass Operations:\n";
    for ($i = 0; $i < 25; ++$i) {
        $cache->delete("temp:file:$i");
    }

    // 7. Cache enumeration
    echo "\n7. Cache Enumeration:\n";
    for ($i = 0; $i < 60; ++$i) {
        $cache->get("unknown:key:$i"); // Many misses = enumeration
    }

    // Get comprehensive security report
    echo "\n=== SECURITY REPORT ===\n";
    $report = $security->getSecurityReport();

    echo "Summary:\n";
    foreach ($report['summary'] as $key => $value) {
        echo '- ' . ucwords(str_replace('_', ' ', $key)) . ": $value\n";
    }

    echo "\nThreat Analysis:\n";
    foreach ($report['threat_analysis'] as $threat => $count) {
        echo '- ' . ucwords(str_replace('_', ' ', $threat)) . ": $count occurrences\n";
    }

    echo "\nSuspicious Activities (last 5):\n";
    $activities = array_slice($report['suspicious_activities'], -5);
    foreach ($activities as $activity) {
        echo "- [{$activity['severity']}] {$activity['description']}\n";
    }

    // Get real-time alerts
    echo "\n=== SECURITY ALERTS ===\n";
    $alerts = $security->getSecurityAlerts();
    foreach ($alerts as $alert) {
        echo "[{$alert['severity']}] {$alert['message']}\n";
    }

    // Test IP blocking
    echo "\n=== IP BLOCKING TEST ===\n";
    $security->blockIP('192.168.1.200', 'Multiple security violations', 3600);
    echo "Blocked IP: 192.168.1.200\n";
    echo 'Is blocked: ' . ($security->isIPBlocked('192.168.1.200') ? 'YES' : 'NO') . "\n";

    // Test whitelisting
    $security->whitelistIP('192.168.1.100');
    echo "Whitelisted IP: 192.168.1.100\n";
    echo 'Is whitelisted: ' . ($security->isIPWhitelisted('192.168.1.100') ? 'YES' : 'NO') . "\n";

    echo "\n";
}

// Example 3: Enterprise Security Setup
function enterpriseSecuritySetupExample()
{
    echo "=== Enterprise Security Setup ===\n";

    // Enterprise-grade logger with multiple handlers
    $logger = new class implements Psr\Log\LoggerInterface {
        private array $handlers = [];

        public function addHandler(callable $handler): void
        {
            $this->handlers[] = $handler;
        }

        public function emergency($message, array $context = []): void
        {
            $this->log('emergency', $message, $context);
        }

        public function alert($message, array $context = []): void
        {
            $this->log('alert', $message, $context);
        }

        public function critical($message, array $context = []): void
        {
            $this->log('critical', $message, $context);
        }

        public function error($message, array $context = []): void
        {
            $this->log('error', $message, $context);
        }

        public function warning($message, array $context = []): void
        {
            $this->log('warning', $message, $context);
        }

        public function notice($message, array $context = []): void
        {
            $this->log('notice', $message, $context);
        }

        public function info($message, array $context = []): void
        {
            $this->log('info', $message, $context);
        }

        public function debug($message, array $context = []): void
        {
            $this->log('debug', $message, $context);
        }

        public function log($level, $message, array $context = []): void
        {
            foreach ($this->handlers as $handler) {
                $handler($level, $message, $context);
            }
        }
    };

    // Add multiple log handlers
    $logger->addHandler(function ($level, $message, $context) {
        echo "[CONSOLE] [$level] $message\n";
    });

    $logger->addHandler(function ($level, $message, $context) {
        if (in_array($level, ['critical', 'emergency'])) {
            // In real implementation, this would send to SIEM/SOC
            echo "[ALERT_SYSTEM] CRITICAL: $message\n";
        }
    });

    // Enterprise security configuration
    $enterpriseConfig = [
        'rate_limit' => 500,
        'whitelisted_ips' => [
            '10.0.0.0/8',      // Internal network
            '192.168.0.0/16',  // Private network
            '172.16.0.0/12',   // Docker networks
        ],
        'audit_salt' => hash('sha256', 'enterprise_salt_' . date('Y-m-d')),
        'retention_days' => 365, // 1 year retention
        'enable_geo_location' => true,
    ];

    $security = new SecurityEventListener($logger, $enterpriseConfig);

    // Create advanced listener provider with enterprise patterns
    $provider = new CacheListenerProvider(true, 10000); // Large cache for enterprise

    // Add compliance listeners
    $provider->addListener(CacheSetEvent::class, function ($event) {
        // GDPR compliance check
        if (preg_match('/personal|email|phone|address/i', $event->getKey())) {
            echo "GDPR: Personal data detected in cache key: {$event->getKey()}\n";
        }
    }, 90);

    // Add PCI DSS compliance
    $provider->addListener(CacheSetEvent::class, function ($event) {
        if (preg_match('/card|payment|billing/i', $event->getKey())) {
            echo "PCI-DSS: Payment data detected in cache key: {$event->getKey()}\n";
        }
    }, 95);

    // Add SOX compliance
    $provider->addListener(CacheDeleteEvent::class, function ($event) {
        if (preg_match('/financial|audit|transaction/i', $event->getKey())) {
            echo "SOX: Financial data deletion detected: {$event->getKey()}\n";
        }
    }, 95);

    // Create enterprise event system
    $dispatcher = CacheEventFactory::createEventDispatcher($provider);
    $security->setEventDispatcher($dispatcher);

    $cache = CacheBuilder::create()
        ->withRedis(['host' => 'localhost', 'port' => 6379])
        ->withApcu()
        ->withEventListener($security)
        ->withEventDispatcher($dispatcher)
        ->build();

    echo "Enterprise security monitoring active.\n";

    // Test compliance scenarios
    echo "\nTesting compliance scenarios:\n";

    // GDPR test
    $cache->set('user:personal:email:123', 'john.doe@example.com');

    // PCI DSS test
    $cache->set('payment:card:token:456', 'tok_1234567890');

    // SOX test
    $cache->delete('financial:transaction:789');

    // Generate compliance report
    echo "\n=== COMPLIANCE REPORT ===\n";
    $report = $security->getSecurityReport();

    echo "Compliance Status:\n";
    foreach ($report['compliance_report'] as $key => $value) {
        $status = is_bool($value) ? ($value ? 'COMPLIANT' : 'NON-COMPLIANT') : $value;
        echo '- ' . ucwords(str_replace('_', ' ', $key)) . ": $status\n";
    }

    // Export audit log for compliance
    echo "\nExporting audit log for compliance...\n";
    $auditLog = $security->exportAuditLog();
    echo 'Audit entries exported: ' . count($auditLog) . "\n";

    // Cleanup old entries
    $cleaned = $security->cleanupAuditLog(30);
    echo "Old audit entries cleaned: $cleaned\n";

    echo "\n";
}

// Example 4: Real-time Threat Detection
function realTimeThreatDetectionExample()
{
    echo "=== Real-time Threat Detection ===\n";

    $logger = new class implements Psr\Log\LoggerInterface {
        public function emergency($message, array $context = []): void
        {
            $this->log('emergency', $message, $context);
        }

        public function alert($message, array $context = []): void
        {
            $this->log('alert', $message, $context);
        }

        public function critical($message, array $context = []): void
        {
            $this->log('critical', $message, $context);
        }

        public function error($message, array $context = []): void
        {
            $this->log('error', $message, $context);
        }

        public function warning($message, array $context = []): void
        {
            $this->log('warning', $message, $context);
        }

        public function notice($message, array $context = []): void
        {
            $this->log('notice', $message, $context);
        }

        public function info($message, array $context = []): void
        {
            $this->log('info', $message, $context);
        }

        public function debug($message, array $context = []): void
        {
            $this->log('debug', $message, $context);
        }

        public function log($level, $message, array $context = []): void
        {
            $timestamp = date('Y-m-d H:i:s');
            echo "[$timestamp] [$level] $message\n";
        }
    };

    $security = new SecurityEventListener($logger);

    // Create threat detection system
    $provider = new CacheListenerProvider();

    // Real-time threat analysis
    $provider->addListener('KaririCode\Cache\Event\CacheSecurityEvent', function ($event) {
        $severity = $event->getContext()['severity'] ?? 'LOW';

        if (in_array($severity, ['CRITICAL', 'HIGH'])) {
            // In real implementation, this would trigger immediate response
            echo "🚨 THREAT DETECTED: {$event->getOperation()} on {$event->getKey()}\n";

            // Auto-block high-risk IPs
            $ip = $event->getContext()['ip'] ?? 'unknown';
            if ('unknown' !== $ip && 'CRITICAL' === $severity) {
                echo "🔒 AUTO-BLOCKING IP: $ip\n";
            }
        }
    }, 100);

    // Behavioral analysis
    $provider->addListener('KaririCode\Cache\Event\CacheSecurityEvent', function ($event) {
        static $activityTracker = [];

        $ip = $event->getContext()['ip'] ?? 'unknown';
        $now = time();

        if (!isset($activityTracker[$ip])) {
            $activityTracker[$ip] = ['operations' => [], 'first_seen' => $now];
        }

        $activityTracker[$ip]['operations'][] = [
            'operation' => $event->getOperation(),
            'timestamp' => $now,
            'key' => $event->getKey(),
        ];

        // Analyze recent activity (last 5 minutes)
        $recentOps = array_filter(
            $activityTracker[$ip]['operations'],
            fn ($op) => ($now - $op['timestamp']) < 300
        );

        if (count($recentOps) > 50) {
            echo "📊 BEHAVIORAL ALERT: High activity from IP $ip (" . count($recentOps) . " ops in 5 min)\n";
        }
    }, 80);

    $dispatcher = CacheEventFactory::createEventDispatcher($provider);
    $security->setEventDispatcher($dispatcher);

    $cache = CacheBuilder::create()
        ->withFilesystem()
        ->withEventListener($security)
        ->build();

    echo "Real-time threat detection system active.\n";
    echo "Simulating various attack scenarios...\n\n";

    // Simulate attack scenarios

    // 1. Rapid enumeration attack
    echo "1. Simulating cache enumeration attack:\n";
    for ($i = 0; $i < 60; ++$i) {
        $cache->get("secret:key:$i");
    }

    // 2. Large payload injection
    echo "\n2. Simulating large payload injection:\n";
    $payload = str_repeat('ATTACK', 100000);
    $cache->set('malicious:payload', $payload);

    // 3. Sensitive data exposure
    echo "\n3. Simulating sensitive data exposure:\n";
    $cache->set('admin:root:password', 'super_secret_admin_pass');

    // 4. Mass deletion attack
    echo "\n4. Simulating mass deletion attack:\n";
    for ($i = 0; $i < 30; ++$i) {
        $cache->delete("important:data:$i");
    }

    // 5. Code injection attempt
    echo "\n5. Simulating code injection:\n";
    $cache->set('user:input', '<?php system($_GET["cmd"]); ?>');

    // Get final threat report
    echo "\n=== FINAL THREAT REPORT ===\n";
    $alerts = $security->getSecurityAlerts();

    foreach ($alerts as $alert) {
        echo "🔍 [{$alert['severity']}] {$alert['type']}: {$alert['message']}\n";
    }

    $stats = $security->getStats();
    echo "\nFinal Statistics:\n";
    echo "- Suspicious Activities: {$stats['suspicious_activities']}\n";
    echo "- Blocked IPs: {$stats['blocked_ips']}\n";
    echo "- Unique IPs: {$stats['unique_ips']}\n";

    echo "\n";
}

// Example 5: Performance and Monitoring
function performanceMonitoringExample()
{
    echo "=== Performance and Monitoring ===\n";

    $provider = new CacheListenerProvider(true, 1000);

    // Add performance monitoring
    $performanceStats = ['operations' => 0, 'total_time' => 0];

    $provider->addListener(CacheHitEvent::class, function ($event) use (&$performanceStats) {
        ++$performanceStats['operations'];
        $performanceStats['total_time'] += $event->getRetrievalTime();
    });

    echo "Testing provider performance...\n";

    // Add many listeners to test performance
    for ($i = 0; $i < 100; ++$i) {
        $provider->addListener(CacheHitEvent::class, function ($event) {
            // Lightweight operation
        }, rand(1, 100));
    }

    $dispatcher = CacheEventFactory::createEventDispatcher($provider);

    // Benchmark listener execution
    $start = microtime(true);

    for ($i = 0; $i < 1000; ++$i) {
        $event = new CacheHitEvent("test:key:$i", "value $i", 0.001);
        $dispatcher->dispatch($event);
    }

    $end = microtime(true);
    $duration = $end - $start;

    echo "Performance Results:\n";
    echo '- 1000 events dispatched in: ' . number_format($duration * 1000, 2) . "ms\n";
    echo '- Average time per event: ' . number_format(($duration / 1000) * 1000, 4) . "ms\n";
    echo '- Events per second: ' . number_format(1000 / $duration, 0) . "\n";

    // Show provider statistics
    $stats = $provider->getStatistics();
    echo "\nProvider Statistics:\n";
    foreach ($stats as $key => $value) {
        echo '- ' . ucwords(str_replace('_', ' ', $key)) . ": $value\n";
    }

    // Export and import configuration
    echo "\nTesting configuration export/import...\n";
    $config = $provider->exportConfiguration();
    echo 'Configuration exported: ' . number_format(strlen(serialize($config)) / 1024, 2) . " KB\n";

    $newProvider = new CacheListenerProvider();
    $newProvider->importConfiguration($config);
    echo "Configuration imported successfully.\n";

    echo "\n";
}

// Run all examples
echo "CacheListenerProvider & SecurityEventListener Examples\n";
echo "=====================================================\n\n";

try {
    advancedListenerProviderExample();
    comprehensiveSecurityExample();
    enterpriseSecuritySetupExample();
    realTimeThreatDetectionExample();
    performanceMonitoringExample();
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "All examples completed successfully!\n";
