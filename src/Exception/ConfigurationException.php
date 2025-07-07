<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

/**
 * Configuration-related cache exceptions.
 */
final class ConfigurationException extends CacheException
{
    public static function missingDriver(string $driver): static
    {
        return static::createException(
            static::ERROR_CODE_BASE + 20,
            'MISSING_DRIVER',
            "Cache driver '{$driver}' is not configured",
            null,
            ['driver' => $driver]
        );
    }

    public static function invalidConfiguration(string $setting, string $reason): static
    {
        return static::createException(
            static::ERROR_CODE_BASE + 21,
            'INVALID_CONFIGURATION',
            "Invalid cache configuration for '{$setting}': {$reason}",
            null,
            ['setting' => $setting, 'reason' => $reason]
        );
    }

    public static function factoryNotFound(string $factoryClass): static
    {
        return static::createException(
            static::ERROR_CODE_BASE + 22,
            'FACTORY_NOT_FOUND',
            "Cache factory class not found: '{$factoryClass}'",
            null,
            ['factory_class' => $factoryClass]
        );
    }
}
