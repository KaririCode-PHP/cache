<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\Factory;

use KaririCode\Cache\Contract\CacheEventListener;
use KaririCode\Cache\Event\Dispatcher\CacheEventDispatcher;
use KaririCode\Cache\Event\DTO\EventSystem;
use KaririCode\Cache\Event\Listener\DefaultCacheEventListener;
use KaririCode\Cache\Event\Provider\CacheListenerProvider;

/**
 * A factory for creating a self-contained, PSR-14 compatible event system.
 */
final class CacheEventFactory
{
    /**
     * @var array<string, mixed>
     */
    private static array $defaultConfig = [
        'max_events' => 1000,
    ];

    /**
     * Creates a complete, self-contained PSR-14 event system.
     *
     * @param array<string, mixed> $config
     */
    public static function createCompleteEventSystem(array $config = []): EventSystem
    {
        $mergedConfig = array_merge(self::$defaultConfig, $config);

        $listenerProvider = new CacheListenerProvider();
        $eventDispatcher = new CacheEventDispatcher($listenerProvider);
        $eventListener = self::createEventListener($mergedConfig);

        return new EventSystem(
            listenerProvider: $listenerProvider,
            eventDispatcher: $eventDispatcher,
            eventListener: $eventListener,
            config: $mergedConfig
        );
    }

    /**
     * Creates a legacy-style cache event listener.
     *
     * @param array<string, mixed> $config
     */
    public static function createEventListener(array $config = []): CacheEventListener
    {
        $maxEventsValue = ($config['max_events'] ?? self::$defaultConfig['max_events']) * 1;

        return new DefaultCacheEventListener($maxEventsValue);
    }
}
