<?php

declare(strict_types=1);

namespace App\Provider;

use App\Bootstrap\CacheBootstrap;
use KaririCode\Cache\Service\CacheManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cache Service Provider for DI Containers.
 *
 * This provider can be used with various DI containers to register
 * the cache system properly configured.
 *
 * @category  Provider
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 */
final class CacheServiceProvider
{
    /**
     * Register cache services in the container.
     */
    public function register(ContainerInterface $container): void
    {
        // Register CacheBootstrap
        $container->set(CacheBootstrap::class, function (ContainerInterface $c) {
            $logger = $c->has(LoggerInterface::class) ? $c->get(LoggerInterface::class) : null;

            return new CacheBootstrap($logger);
        });

        // Register CacheManager
        $container->set(CacheManager::class, function (ContainerInterface $c) {
            /** @var CacheBootstrap $bootstrap */
            $bootstrap = $c->get(CacheBootstrap::class);

            return $bootstrap->createCacheManager();
        });

        // Register convenient aliases
        $container->set('cache', $container->get(CacheManager::class));
        $container->set('cache.manager', $container->get(CacheManager::class));
    }
}
