<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\ArrayDriver;
use KaririCode\Cache\Provider\ConfigurableExpirationProvider;

/**
 * Factory for creating ArrayDriver with ExpirationProvider.
 * Perfect for testing and request-scoped caching.
 */
final class ArrayDriverFactory implements CacheDriverFactory
{
    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        $expirationProvider = $this->createExpirationProvider($options, $manager);

        return new ArrayDriver($expirationProvider);
    }

    /**
     * @return list<string>
     */
    public function getSupportedOptions(): array
    {
        return [
            'expiration_provider',
            'default_ttl',
            'operation_ttls',
            'key_pattern_ttls',
        ];
    }

    public function validateOptions(array $options): bool
    {
        // Array driver has minimal validation requirements
        if (isset($options['default_ttl']) && (!is_int($options['default_ttl']) || $options['default_ttl'] < 0)) {
            return false;
        }

        if (isset($options['operation_ttls']) && !is_array($options['operation_ttls'])) {
            return false;
        }

        if (isset($options['key_pattern_ttls']) && !is_array($options['key_pattern_ttls'])) {
            return false;
        }

        return true;
    }

    private function createExpirationProvider(array $options, DriverManager $manager): ExpirationProvider
    {
        if (isset($options['expiration_provider']) && $options['expiration_provider'] instanceof ExpirationProvider) {
            return $options['expiration_provider'];
        }

        return new ConfigurableExpirationProvider(
            $options['default_ttl'] ?? 3600,
            $options['operation_ttls'] ?? [
                'set' => 3600,
                'setMultiple' => 3600,
                'add' => 3600,
                'increment' => 7200,
                'decrement' => 7200,
                'setWithTags' => 1800,
            ],
            $options['key_pattern_ttls'] ?? [
                'session:*' => 7200,
                'temp:*' => 300,
                'cache:*' => 3600,
            ]
        );
    }
}
