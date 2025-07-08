<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

use KaririCode\Exception\AbstractException;

/**
 * Configuration-related cache exceptions.
 */
final class ConfigurationException extends AbstractException
{
    private const CODE_MISSING_DRIVER = 1220;
    private const CODE_INVALID_CONFIGURATION = 1221;
    private const CODE_FACTORY_NOT_FOUND = 1222;
    private const CODE_INVALID_FACTORY = 1223;
    private const CODE_MISSING_EXTENSION = 1224;
    private const CODE_INVALID_OPTION = 1225;
    private const CODE_DIRECTORY_NOT_WRITABLE = 1226;
    private const CODE_INVALID_HOST = 1227;
    private const CODE_INVALID_PORT = 1228;
    private const CODE_CONNECTION_TIMEOUT = 1229;

    public static function missingDriver(string $driver): static
    {
        return static::createException(
            self::CODE_MISSING_DRIVER,
            'MISSING_DRIVER',
            "Cache driver '{$driver}' is not configured"
        );
    }

    public static function invalidConfiguration(string $setting, string $reason): static
    {
        return static::createException(
            self::CODE_INVALID_CONFIGURATION,
            'INVALID_CONFIGURATION',
            "Invalid cache configuration for '{$setting}': {$reason}"
        );
    }

    public static function factoryNotFound(string $factoryClass): static
    {
        return static::createException(
            self::CODE_FACTORY_NOT_FOUND,
            'FACTORY_NOT_FOUND',
            "Cache factory class not found: '{$factoryClass}'"
        );
    }

    public static function invalidFactory(string $name, string $factoryClass, string $expectedInterface): static
    {
        return static::createException(
            self::CODE_INVALID_FACTORY,
            'INVALID_FACTORY',
            "Cache factory '{$name}' must implement '{$expectedInterface}', got '{$factoryClass}'"
        );
    }

    public static function missingExtension(string $extension): static
    {
        return static::createException(
            self::CODE_MISSING_EXTENSION,
            'MISSING_EXTENSION',
            "Required PHP extension '{$extension}' is not loaded"
        );
    }

    public static function invalidOption(string $option, mixed $value, string $expected): static
    {
        $type = is_object($value) ? get_class($value) : gettype($value);

        return static::createException(
            self::CODE_INVALID_OPTION,
            'INVALID_OPTION',
            "Invalid value for option '{$option}': expected {$expected}, got {$type}"
        );
    }

    public static function directoryNotWritable(string $directory): static
    {
        return static::createException(
            self::CODE_DIRECTORY_NOT_WRITABLE,
            'DIRECTORY_NOT_WRITABLE',
            "Cache directory is not writable: {$directory}"
        );
    }

    public static function invalidHost(string $host): static
    {
        return static::createException(
            self::CODE_INVALID_HOST,
            'INVALID_HOST',
            "Invalid cache server host: {$host}"
        );
    }

    public static function invalidPort(int $port): static
    {
        return static::createException(
            self::CODE_INVALID_PORT,
            'INVALID_PORT',
            "Invalid cache server port: {$port}. Must be between 1 and 65535"
        );
    }

    public static function connectionTimeout(string $server, int $timeout): static
    {
        return static::createException(
            self::CODE_CONNECTION_TIMEOUT,
            'CONNECTION_TIMEOUT',
            "Connection timeout to cache server '{$server}' after {$timeout} seconds"
        );
    }
}
