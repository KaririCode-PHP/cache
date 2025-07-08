<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\Factory;

use KaririCode\Cache\Contract\CacheEventListener;
use KaririCode\Cache\Event\CacheClearEvent;
use KaririCode\Cache\Event\CacheDeleteEvent;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Event\CacheSetEvent;
use KaririCode\Cache\Event\CacheWarmupEvent;
use KaririCode\Cache\Event\Dispatcher\CacheEventDispatcher;
use KaririCode\Cache\Event\Listener\AnalyticsEventListener;
use KaririCode\Cache\Event\Listener\DefaultCacheEventListener;
use KaririCode\Cache\Event\Listener\PerformanceEventListener;
use KaririCode\Cache\Event\Listener\SecurityEventListener;
use KaririCode\Cache\Event\Provider\CacheListenerProvider;
use KaririCode\Cache\Event\TagInvalidationEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Comprehensive factory for creating PSR-14 compatible cache event components.
 *
 * This factory follows the Abstract Factory pattern and provides a centralized
 * way to create all event-related components with proper dependency injection
 * and configuration management.
 *s
 */
final class CacheEventFactory
{
    private static array $defaultConfig = [
        'max_events' => 1000,
        'enable_performance_tracking' => true,
        'enable_security_monitoring' => false,
        'default_priority' => 0,
        'event_namespaces' => [
            'hit' => CacheHitEvent::class,
            'miss' => CacheMissEvent::class,
            'set' => CacheSetEvent::class,
            'delete' => CacheDeleteEvent::class,
            'clear' => CacheClearEvent::class,
            'tag_invalidation' => TagInvalidationEvent::class,
            'warmup' => CacheWarmupEvent::class,
        ],
    ];

    /**
     * Create a PSR-14 compatible event dispatcher.
     */
    public static function createEventDispatcher(?ListenerProviderInterface $listenerProvider = null): EventDispatcherInterface
    {
        return new CacheEventDispatcher($listenerProvider);
    }

    /**
     * Create a PSR-14 compatible listener provider.
     */
    public static function createListenerProvider(array $config = []): ListenerProviderInterface
    {
        return new CacheListenerProvider();
    }

    /**
     * Create a cache event listener with optional PSR-14 integration.
     */
    public static function createEventListener(
        ?EventDispatcherInterface $eventDispatcher = null,
        array $config = []
    ): CacheEventListener {
        $mergedConfig = array_merge(self::$defaultConfig, $config);

        $listener = new DefaultCacheEventListener($mergedConfig['max_events']);

        if ($eventDispatcher) {
            $listener->setEventDispatcher($eventDispatcher);
        }

        return $listener;
    }

    /**
     * Create a complete PSR-14 event system with all components configured.
     */
    public static function createCompleteEventSystem(array $config = []): array
    {
        $mergedConfig = array_merge(self::$defaultConfig, $config);

        $listenerProvider = self::createListenerProvider($mergedConfig);
        $eventDispatcher = self::createEventDispatcher($listenerProvider);
        $eventListener = self::createEventListener($eventDispatcher, $mergedConfig);

        return [
            'listenerProvider' => $listenerProvider,
            'eventDispatcher' => $eventDispatcher,
            'eventListener' => $eventListener,
            'config' => $mergedConfig,
        ];
    }

    /**
     * Create an event system with analytics capabilities.
     */
    public static function createAnalyticsEventSystem(): array
    {
        $eventSystem = self::createCompleteEventSystem([
            'enable_performance_tracking' => true,
            'max_events' => 5000,
        ]);

        $analytics = new AnalyticsEventListener();

        // Add analytics listeners to the dispatcher
        $dispatcher = $eventSystem['eventDispatcher'];
        if (method_exists($dispatcher, 'addListener')) {
            $dispatcher->addListener(CacheHitEvent::class, [$analytics, 'onCacheHit']);
            $dispatcher->addListener(CacheMissEvent::class, [$analytics, 'onCacheMiss']);
            $dispatcher->addListener(CacheSetEvent::class, [$analytics, 'onCacheSet']);
            $dispatcher->addListener(CacheDeleteEvent::class, [$analytics, 'onCacheDelete']);
            $dispatcher->addListener(CacheClearEvent::class, [$analytics, 'onCacheClear']);
        }

        $eventSystem['analytics'] = $analytics;

        return $eventSystem;
    }

    /**
     * Create an event system with performance monitoring.
     */
    public static function createPerformanceEventSystem(float $slowThreshold = 0.1): array
    {
        $eventSystem = self::createCompleteEventSystem();

        $performance = new PerformanceEventListener($slowThreshold);

        // Add performance listeners to the dispatcher
        $dispatcher = $eventSystem['eventDispatcher'];
        if (method_exists($dispatcher, 'addListener')) {
            $dispatcher->addListener(CacheHitEvent::class, [$performance, 'onCacheHit']);
            $dispatcher->addListener(CacheMissEvent::class, [$performance, 'onCacheMiss']);
            $dispatcher->addListener(CacheSetEvent::class, [$performance, 'onCacheSet']);
            $dispatcher->addListener(CacheDeleteEvent::class, [$performance, 'onCacheDelete']);
            $dispatcher->addListener(CacheClearEvent::class, [$performance, 'onCacheClear']);
        }

        $eventSystem['performance'] = $performance;

        return $eventSystem;
    }

    /**
     * Create an event system with security monitoring.
     */
    public static function createSecurityEventSystem(\Psr\Log\LoggerInterface $logger): array
    {
        $eventSystem = self::createCompleteEventSystem([
            'enable_security_monitoring' => true,
        ]);

        $security = new SecurityEventListener($logger);

        // Add security listeners to the dispatcher
        $dispatcher = $eventSystem['eventDispatcher'];
        if (method_exists($dispatcher, 'addListener')) {
            $dispatcher->addListener(CacheHitEvent::class, [$security, 'onCacheHit']);
            $dispatcher->addListener(CacheMissEvent::class, [$security, 'onCacheMiss']);
            $dispatcher->addListener(CacheSetEvent::class, [$security, 'onCacheSet']);
            $dispatcher->addListener(CacheDeleteEvent::class, [$security, 'onCacheDelete']);
            $dispatcher->addListener(CacheClearEvent::class, [$security, 'onCacheClear']);
        }

        $eventSystem['security'] = $security;

        return $eventSystem;
    }

    /**
     * Create an event system with custom listener mappings.
     */
    public static function createCustomEventSystem(array $listenerMappings, array $config = []): array
    {
        $eventSystem = self::createCompleteEventSystem($config);
        $dispatcher = $eventSystem['eventDispatcher'];

        if (method_exists($dispatcher, 'addListener')) {
            foreach ($listenerMappings as $eventType => $listeners) {
                if (is_array($listeners)) {
                    foreach ($listeners as $listener) {
                        $priority = $listener['priority'] ?? self::$defaultConfig['default_priority'];
                        $dispatcher->addListener($eventType, $listener['callable'], $priority);
                    }
                } else {
                    $dispatcher->addListener($eventType, $listeners);
                }
            }
        }

        return $eventSystem;
    }

    /**
     * Create a minimal event system for high-performance scenarios.
     */
    public static function createMinimalEventSystem(): array
    {
        return [
            'listenerProvider' => self::createListenerProvider(),
            'eventDispatcher' => self::createEventDispatcher(),
            'eventListener' => self::createEventListener(null, ['max_events' => 100]),
            'config' => ['minimal' => true],
        ];
    }

    /**
     * Create an event system that bridges with external dispatchers.
     */
    public static function createBridgedEventSystem(
        EventDispatcherInterface $externalDispatcher,
        array $eventMapping = []
    ): array {
        $defaultMapping = self::$defaultConfig['event_namespaces'];
        $mapping = array_merge($defaultMapping, $eventMapping);

        // Create a bridge that forwards events to the external dispatcher
        $bridge = new class($externalDispatcher, $mapping) implements EventDispatcherInterface {
            public function __construct(
                private EventDispatcherInterface $externalDispatcher,
                private array $eventMapping
            ) {
            }

            public function dispatch(object $event): object
            {
                // Dispatch to external dispatcher
                $this->externalDispatcher->dispatch($event);

                return $event;
            }
        };

        return [
            'eventDispatcher' => $bridge,
            'externalDispatcher' => $externalDispatcher,
            'eventListener' => self::createEventListener($bridge),
            'mapping' => $mapping,
        ];
    }

    /**
     * Register default event listeners for common use cases.
     */
    public static function registerDefaultListeners(
        EventDispatcherInterface $dispatcher,
        array $config = []
    ): void {
        if (!method_exists($dispatcher, 'addListener')) {
            return;
        }

        $enableDebug = $config['debug'] ?? false;
        $enableMetrics = $config['metrics'] ?? true;
        $enableSecurity = $config['security'] ?? false;

        if ($enableDebug) {
            self::registerDebugListeners($dispatcher);
        }

        if ($enableMetrics) {
            self::registerMetricsListeners($dispatcher);
        }

        if ($enableSecurity) {
            self::registerSecurityListeners($dispatcher);
        }
    }

    /**
     * Create event listeners with automatic registration.
     */
    public static function createAutoRegisteredEventSystem(array $options = []): array
    {
        $eventSystem = self::createCompleteEventSystem();

        self::registerDefaultListeners(
            $eventSystem['eventDispatcher'],
            $options
        );

        return $eventSystem;
    }

    /**
     * Validate event system configuration.
     */
    public static function validateConfiguration(array $config): array
    {
        $errors = [];

        if (isset($config['max_events']) && (!is_int($config['max_events']) || $config['max_events'] < 1)) {
            $errors[] = 'max_events must be a positive integer';
        }

        if (isset($config['default_priority']) && !is_int($config['default_priority'])) {
            $errors[] = 'default_priority must be an integer';
        }

        if (isset($config['event_namespaces']) && !is_array($config['event_namespaces'])) {
            $errors[] = 'event_namespaces must be an array';
        }

        return $errors;
    }

    /**
     * Get default configuration.
     */
    public static function getDefaultConfig(): array
    {
        return self::$defaultConfig;
    }

    /**
     * Set default configuration.
     */
    public static function setDefaultConfig(array $config): void
    {
        self::$defaultConfig = array_merge(self::$defaultConfig, $config);
    }

    /**
     * Get available event types.
     */
    public static function getAvailableEventTypes(): array
    {
        return array_values(self::$defaultConfig['event_namespaces']);
    }

    /**
     * Check if an event type is supported.
     */
    public static function isEventTypeSupported(string $eventType): bool
    {
        return in_array($eventType, self::getAvailableEventTypes(), true);
    }

    /**
     * Register debug listeners for development.
     */
    private static function registerDebugListeners(EventDispatcherInterface $dispatcher): void
    {
        if (!method_exists($dispatcher, 'addListener')) {
            return;
        }

        $dispatcher->addListener(CacheHitEvent::class, function (CacheHitEvent $event) {
            error_log("DEBUG: Cache HIT - {$event->getKey()} ({$event->getValueType()})");
        });

        $dispatcher->addListener(CacheMissEvent::class, function (CacheMissEvent $event) {
            error_log("DEBUG: Cache MISS - {$event->getKey()}");
        });

        $dispatcher->addListener(CacheSetEvent::class, function (CacheSetEvent $event) {
            error_log("DEBUG: Cache SET - {$event->getKey()} ({$event->getValueType()})");
        });
    }

    /**
     * Register metrics listeners.
     */
    private static function registerMetricsListeners(EventDispatcherInterface $dispatcher): void
    {
        if (!method_exists($dispatcher, 'addListener')) {
            return;
        }

        static $metrics = ['hits' => 0, 'misses' => 0, 'sets' => 0];

        $dispatcher->addListener(CacheHitEvent::class, function () use (&$metrics) {
            ++$metrics['hits'];
        });

        $dispatcher->addListener(CacheMissEvent::class, function () use (&$metrics) {
            ++$metrics['misses'];
        });

        $dispatcher->addListener(CacheSetEvent::class, function () use (&$metrics) {
            ++$metrics['sets'];
        });
    }

    /**
     * Register security listeners.
     */
    private static function registerSecurityListeners(EventDispatcherInterface $dispatcher): void
    {
        if (!method_exists($dispatcher, 'addListener')) {
            return;
        }

        $dispatcher->addListener(CacheSetEvent::class, function (CacheSetEvent $event) {
            if ($event->getValueSize() > 1024 * 1024) { // 1MB
                error_log("SECURITY: Large cache value detected - {$event->getKey()}");
            }
        });

        $dispatcher->addListener(CacheDeleteEvent::class, function (CacheDeleteEvent $event) {
            if (preg_match('/admin|config|secret/i', $event->getKey())) {
                error_log("SECURITY: Sensitive cache key deleted - {$event->getKey()}");
            }
        });
    }
}
