<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\Factory;

use KaririCode\Cache\Config\CacheConfig;
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
    public function __construct(private readonly CacheConfig $config)
    {
    }

    /**
     * Creates a complete, self-contained PSR-14 event system.
     */
    public function createCompleteEventSystem(): EventSystem
    {
        $observabilityConfig = $this->config->getObservabilityConfig();

        $listenerProvider = new CacheListenerProvider();
        $eventDispatcher = new CacheEventDispatcher($listenerProvider);
        $eventListener = $this->createEventListener();

        return new EventSystem(
            listenerProvider: $listenerProvider,
            eventDispatcher: $eventDispatcher,
            eventListener: $eventListener,
            config: $observabilityConfig
        );
    }

    /**
     * Creates a legacy-style cache event listener.
     */
    public function createEventListener(): CacheEventListener
    {
        $maxEvents = $this->config->getObservabilityConfig()->maxEvents;

        return new DefaultCacheEventListener($maxEvents);
    }
}
