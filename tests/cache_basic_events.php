<?php

declare(strict_types=1);

/**
 * CacheEventFactory Usage Examples.
 *
 * Demonstrates how to use the enhanced CacheEventFactory for different scenarios.
 */

require __DIR__ . '/../vendor/autoload.php';

use KaririCode\Cache\CacheBuilder;
use KaririCode\Cache\Event\CacheEventFactory;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Event\CacheSetEvent;

// Example 1: Basic Event System Creation
function basicEventSystemExample()
{
    echo "=== Basic Event System Creation ===\n";

    // Create a complete event system with default settings
    $eventSystem = CacheEventFactory::createCompleteEventSystem();

    echo "Created components:\n";
    echo '- Event Dispatcher: ' . get_class($eventSystem['eventDispatcher']) . "\n";
    echo '- Listener Provider: ' . get_class($eventSystem['listenerProvider']) . "\n";
    echo '- Event Listener: ' . get_class($eventSystem['eventListener']) . "\n";
    echo '- Max Events: ' . $eventSystem['config']['max_events'] . "\n";

    // Add a simple listener
    $dispatcher = $eventSystem['eventDispatcher'];
    if (method_exists($dispatcher, 'addListener')) {
        $dispatcher->addListener(CacheHitEvent::class, function (CacheHitEvent $event) {
            echo "Basic System: Cache hit for {$event->getKey()}\n";
        });
    }

    // Test with a cache
    $cache = CacheBuilder::create()
        ->withFilesystem()
        ->withEventDispatcher($dispatcher)
        ->build();

    $cache->set('test:key', 'test value');
    $cache->get('test:key');

    echo "\n";
}

// Example 2: Analytics Event System
function analyticsEventSystemExample()
{
    echo "=== Analytics Event System ===\n";

    // Create event system with analytics capabilities
    $eventSystem = CacheEventFactory::createAnalyticsEventSystem();

    echo "Analytics system created with enhanced tracking\n";

    $cache = CacheBuilder::create()
        ->withFilesystem()
        ->withEventDispatcher($eventSystem['eventDispatcher'])
        ->build();

    // Generate some cache activity
    for ($i = 1; $i <= 5; ++$i) {
        $cache->set("user:$i", ['id' => $i, 'name' => "User $i"]);
        $cache->get("user:$i");
        if (0 === $i % 2) {
            $cache->get("user:missing:$i"); // Generate misses
        }
    }

    // Get analytics insights
    if (isset($eventSystem['analytics'])) {
        $insights = $eventSystem['analytics']->getBusinessInsights();
        echo 'Cache Efficiency: ' . number_format($insights['cache_efficiency']['hit_rate'] * 100, 2) . "%\n";
        echo 'Total Requests: ' . $insights['cache_efficiency']['total_requests'] . "\n";
        echo 'Popular Data Types: ' . json_encode($insights['popular_data_types']) . "\n";
    }

    echo "\n";
}

// Example 3: Performance Monitoring System
function performanceEventSystemExample()
{
    echo "=== Performance Monitoring System ===\n";

    // Create event system with performance monitoring (slow threshold: 50ms)
    $eventSystem = CacheEventFactory::createPerformanceEventSystem(0.05);

    $cache = CacheBuilder::create()
        ->withFilesystem()
        ->withEventDispatcher($eventSystem['eventDispatcher'])
        ->build();

    // Generate cache operations
    $cache->set('fast:operation', 'quick data');
    $cache->get('fast:operation');

    // Simulate slow operation (large data)
    $largeData = str_repeat('x', 100000);
    $cache->set('slow:operation', $largeData);
    $cache->get('slow:operation');

    // Get performance report
    if (isset($eventSystem['performance'])) {
        $report = $eventSystem['performance']->getPerformanceReport();
        echo 'Total Operations: ' . $report['total_operations'] . "\n";
        echo 'Slow Operations: ' . count($report['slow_operations']) . "\n";

        if (!empty($report['average_times'])) {
            echo 'Average Times: ' . json_encode($report['average_times']) . "\n";
        }
    }

    echo "\n";
}

// Example 4: Security Monitoring System
function securityEventSystemExample()
{
    echo "=== Security Monitoring System ===\n";

    // Create logger for security events
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
            $this->logs[] = "[$level] $message";
            echo "Security Log: [$level] $message\n";
        }

        public function getLogs(): array
        {
            return $this->logs;
        }
    };

    // Create event system with security monitoring
    $eventSystem = CacheEventFactory::createSecurityEventSystem($logger);

    $cache = CacheBuilder::create()
        ->withFilesystem()
        ->withEventDispatcher($eventSystem['eventDispatcher'])
        ->build();

    // Normal operations
    $cache->set('normal:data', 'safe content');

    // Potentially suspicious operations
    $cache->set('admin:password', 'secret123');
    $cache->delete('config:database');

    // Large data that might trigger security alerts
    $suspiciousData = str_repeat('malicious', 50000);
    $cache->set('suspicious:payload', $suspiciousData);

    // Get security report
    if (isset($eventSystem['security'])) {
        $report = $eventSystem['security']->getSecurityReport();
        echo 'Security Events Recorded: ' . $report['total_events'] . "\n";
        echo 'Unique IPs: ' . count($report['unique_ips']) . "\n";
        echo 'Suspicious Activities: ' . count($report['suspicious_activities']) . "\n";
    }

    echo "\n";
}

// Example 5: Custom Event System with Mappings
function customEventSystemExample()
{
    echo "=== Custom Event System with Mappings ===\n";

    // Define custom listener mappings
    $listenerMappings = [
        CacheHitEvent::class => [
            [
                'callable' => function (CacheHitEvent $event) {
                    echo "High Priority: Hit for {$event->getKey()}\n";
                },
                'priority' => 100,
            ],
            [
                'callable' => function (CacheHitEvent $event) {
                    echo "Low Priority: Hit for {$event->getKey()}\n";
                },
                'priority' => 10,
            ],
        ],
        CacheSetEvent::class => function (CacheSetEvent $event) {
            echo "Custom: Set {$event->getKey()} ({$event->getValueType()})\n";
        },
    ];

    // Create custom event system
    $eventSystem = CacheEventFactory::createCustomEventSystem($listenerMappings, [
        'max_events' => 500,
        'enable_performance_tracking' => true,
    ]);

    $cache = CacheBuilder::create()
        ->withFilesystem()
        ->withEventDispatcher($eventSystem['eventDispatcher'])
        ->build();

    // Test custom listeners
    $cache->set('custom:key', 'custom value');
    $cache->get('custom:key');

    echo "\n";
}

// Example 6: Minimal High-Performance System
function minimalEventSystemExample()
{
    echo "=== Minimal High-Performance System ===\n";

    // Create minimal system for high-performance scenarios
    $eventSystem = CacheEventFactory::createMinimalEventSystem();

    echo "Minimal system created:\n";
    echo '- Minimal mode: ' . ($eventSystem['config']['minimal'] ? 'YES' : 'NO') . "\n";
    echo "- Max events: 100 (reduced for performance)\n";

    $cache = CacheBuilder::create()
        ->withFilesystem()
        ->withEventDispatcher($eventSystem['eventDispatcher'])
        ->build();

    // Test minimal overhead
    $start = microtime(true);
    for ($i = 0; $i < 1000; ++$i) {
        $cache->set("perf:$i", "value $i");
        $cache->get("perf:$i");
    }
    $end = microtime(true);

    echo '1000 operations completed in: ' . number_format(($end - $start) * 1000, 2) . "ms\n";

    echo "\n";
}

// Example 7: Auto-Registered Event System
function autoRegisteredEventSystemExample()
{
    echo "=== Auto-Registered Event System ===\n";

    // Create system with automatic listener registration
    $eventSystem = CacheEventFactory::createAutoRegisteredEventSystem([
        'debug' => true,
        'metrics' => true,
        'security' => true,
    ]);

    echo "Auto-registered system with debug, metrics, and security listeners\n";

    $cache = CacheBuilder::create()
        ->withFilesystem()
        ->withEventDispatcher($eventSystem['eventDispatcher'])
        ->build();

    // Test auto-registered listeners
    $cache->set('auto:key', 'auto value');
    $cache->get('auto:key');
    $cache->set('admin:sensitive', 'sensitive data'); // Should trigger security listener

    echo "\n";
}

// Example 8: Configuration Validation
function configurationValidationExample()
{
    echo "=== Configuration Validation ===\n";

    // Test valid configuration
    $validConfig = [
        'max_events' => 2000,
        'default_priority' => 50,
        'enable_performance_tracking' => true,
    ];

    $errors = CacheEventFactory::validateConfiguration($validConfig);
    echo 'Valid config errors: ' . (empty($errors) ? 'None' : implode(', ', $errors)) . "\n";

    // Test invalid configuration
    $invalidConfig = [
        'max_events' => -100, // Invalid: negative
        'default_priority' => 'high', // Invalid: not integer
        'event_namespaces' => 'invalid', // Invalid: not array
    ];

    $errors = CacheEventFactory::validateConfiguration($invalidConfig);
    echo 'Invalid config errors: ' . implode(', ', $errors) . "\n";

    // Show available event types
    echo "Available event types:\n";
    foreach (CacheEventFactory::getAvailableEventTypes() as $eventType) {
        echo "  - $eventType\n";
    }

    echo "\n";
}

// Run all examples
echo "CacheEventFactory Usage Examples\n";
echo "=================================\n\n";

try {
    basicEventSystemExample();
    analyticsEventSystemExample();
    performanceEventSystemExample();
    securityEventSystemExample();
    customEventSystemExample();
    minimalEventSystemExample();
    autoRegisteredEventSystemExample();
    configurationValidationExample();
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "Examples completed!\n";
