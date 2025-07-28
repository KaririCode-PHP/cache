<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\DTO;

use KaririCode\Cache\Config\DTO\ObservabilityConfig;
use KaririCode\Cache\Contract\CacheEventListener;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * A DTO to hold a complete, configured event system.
 */
final class EventSystem
{
    public function __construct(
        public readonly ListenerProviderInterface $listenerProvider,
        public readonly EventDispatcherInterface $eventDispatcher,
        public readonly ?CacheEventListener $eventListener = null,
        public readonly ?ObservabilityConfig $config = null
    ) {
    }
}
