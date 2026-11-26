<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\ArrayDriver;
use KaririCode\Contract\Logging\Logger;

/**
 * Factory for creating an ArrayDriver instance.
 *
 * This factory is responsible for instantiating the in-memory ArrayDriver.
 * It's ideal for testing, development, or any scenario where a non-persistent,
 * request-scoped cache is needed.
 *
 * It relies on the AbstractDriverFactory to receive a pre-configured
 * ExpirationProvider, ensuring consistent TTL management.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ArrayDriverFactory extends AbstractDriverFactory
{
    /**
     * Creates the ArrayDriver instance.
     *
     * The ArrayDriver has a simple dependency on the ExpirationProvider,
     * which is provided by the abstract factory's orchestration method.
     *
     * @param array<string, mixed> $options the validated driver configuration
     * @param DriverManager $manager the driver manager for resolving shared dependencies
     * @param ExpirationProvider $expirationProvider the centrally configured expiration provider
     * @param Logger $logger the logger instance
     *
     * @return CacheDriver the fully configured ArrayDriver instance
     */
    protected function doCreateDriver(
        array $options,
        DriverManager $manager,
        ExpirationProvider $expirationProvider,
        Logger $logger
    ): CacheDriver {
        // The ArrayDriver's constructor is simple and only requires the expiration provider.
        return new ArrayDriver($expirationProvider);
    }

    /**
     * Returns the unique name of the driver.
     *
     * @return string the driver's name
     */
    protected function getDriverName(): string
    {
        return 'array';
    }

    /**
     * Specifies the configuration options required for this driver.
     *
     * @return array<string> a list of required option keys
     */
    protected function getRequiredOptions(): array
    {
        // The ArrayDriver can run without any specific configuration.
        return [];
    }

    /**
     * Performs driver-specific validation of the provided options.
     *
     * @param array<string, mixed> $options the options to validate
     *
     * @return bool true if the options are valid
     */
    protected function doValidateOptions(array $options): bool
    {
        // The array driver has no specific options to validate beyond what the
        // ExpirationProvider configuration requires, which is handled externally.
        return true;
    }
}
