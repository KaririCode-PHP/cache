<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use KaririCode\Cache\Contract\CacheEventListener;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Factory for creating PSR-14 compatible cache components.
 */
final class Psr14CacheFactory
{
    public static function createEventDispatcher(?ListenerProviderInterface $listenerProvider = null): EventDispatcherInterface
    {
        return new CacheEventDispatcher($listenerProvider);
    }

    public static function createListenerProvider(): ListenerProviderInterface
    {
        return new SimpleCacheListenerProvider();
    }

    public static function createEventBridge(
        EventDispatcherInterface $eventDispatcher,
        ?CacheEventListener $legacyListener = null
    ): CacheEventBridge {
        return new CacheEventBridge($eventDispatcher, $legacyListener);
    }

    public static function createCompleteEventSystem(): array
    {
        $listenerProvider = self::createListenerProvider();
        $eventDispatcher = self::createEventDispatcher($listenerProvider);
        $eventBridge = self::createEventBridge($eventDispatcher);

        return [
            'listenerProvider' => $listenerProvider,
            'eventDispatcher' => $eventDispatcher,
            'eventBridge' => $eventBridge,
        ];
    }
}
