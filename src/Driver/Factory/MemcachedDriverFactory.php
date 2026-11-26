<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedAtomicOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedBatchOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedCrudOperations;
use KaririCode\Cache\Driver\Memcached\Operation\MemcachedTagOperations;
use KaririCode\Cache\Driver\MemcachedDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\Logging\Logger;

/**
 * Factory for creating a fully-configured MemcachedDriver.
 *
 * This factory composes the MemcachedDriver with all its required dependencies.
 * It handles the instantiation and configuration of the underlying Memcached
 * client and all specialized operation handlers.
 *
 * It adheres to the AbstractDriverFactory contract, expecting a pre-configured
 * ExpirationProvider to be passed in, ensuring consistent TTL management.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class MemcachedDriverFactory extends AbstractDriverFactory
{
    private const REQUIRED_EXTENSION = 'memcached';

    /**
     * Creates the MemcachedDriver instance.
     *
     * Assembles the MemcachedDriver by creating and injecting all its dependencies,
     * including the Memcached client, connection manager, and operation handlers.
     *
     * @param array<string, mixed> $options the validated driver configuration
     * @param DriverManager $manager the driver manager for resolving shared dependencies
     * @param ExpirationProvider $expirationProvider the centrally configured expiration provider
     * @param Logger $logger the logger instance
     *
     * @return CacheDriver the fully configured MemcachedDriver instance
     */
    protected function doCreateDriver(
        array $options,
        DriverManager $manager,
        ExpirationProvider $expirationProvider,
        Logger $logger
    ): CacheDriver {
        $config = MemcachedConfig::fromArray($options);

        // Build Core Components
        $memcached = $this->createMemcachedInstance($config);
        $connectionManager = new MemcachedConnectionManager($memcached, $config, $logger);
        $connectionManager->ensureConnection();

        $errorHandler = new MemcachedErrorHandler($logger);
        $monitoringService = new MemcachedMonitoringService($memcached, $logger);

        // Build Operation Handlers, injecting the ExpirationProvider
        $crudOperations = new MemcachedCrudOperations(
            $memcached,
            $config,
            $errorHandler,
            $connectionManager,
            $monitoringService,
            $expirationProvider
        );

        $batchOperations = new MemcachedBatchOperations(
            $memcached,
            $config,
            $errorHandler,
            $connectionManager,
            $monitoringService,
            $expirationProvider
        );

        $atomicOperations = new MemcachedAtomicOperations(
            $memcached,
            $config,
            $errorHandler,
            $connectionManager,
            $monitoringService,
            $expirationProvider
        );

        $tagOperations = new MemcachedTagOperations(
            $memcached,
            $config,
            $errorHandler,
            $connectionManager,
            $monitoringService,
            $expirationProvider
        );

        // Assemble the final driver
        return new MemcachedDriver(
            $crudOperations,
            $batchOperations,
            $atomicOperations,
            $tagOperations,
            $monitoringService,
            $connectionManager,
            $expirationProvider
        );
    }

    /**
     * Returns the unique name of the driver.
     *
     * @return string the driver's name
     */
    protected function getDriverName(): string
    {
        return 'memcached';
    }

    /**
     * Specifies the configuration options required for this driver.
     *
     * @return array<string> a list of required option keys
     */
    protected function getRequiredOptions(): array
    {
        // A server list is technically required but can be defaulted.
        return [];
    }

    /**
     * Specifies the optional configuration options supported by this driver.
     *
     * @return array<string> a list of optional option keys
     */
    protected function getOptionalOptions(): array
    {
        return [
            'servers', 'persistent_id', 'username', 'password',
            'compression', 'serializer', 'key_prefix', 'options',
            'connect_timeout', 'poll_timeout', 'recv_timeout', 'send_timeout',
            'sasl_auth',
        ];
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
        // Validation is handled by the MemcachedConfig DTO.
        MemcachedConfig::fromArray($options);

        return true;
    }

    /**
     * Validates the runtime environment prerequisites for the Memcached driver.
     *
     * @throws ConfigurationException if the required 'memcached' extension is not loaded
     */
    protected function validatePrerequisites(): void
    {
        if (!extension_loaded(self::REQUIRED_EXTENSION)) {
            throw ConfigurationException::missingExtension(self::REQUIRED_EXTENSION);
        }
    }

    /**
     * Creates and configures a new \Memcached instance based on the config DTO.
     *
     * @param MemcachedConfig $config the configuration DTO
     *
     * @return \Memcached the configured client instance
     */
    private function createMemcachedInstance(MemcachedConfig $config): \Memcached
    {
        $memcached = new \Memcached($config->persistentId ?? '');

        // Apply all configuration options from the DTO
        $memcached->setOption(\Memcached::OPT_COMPRESSION, $config->compression);
        $memcached->setOption(\Memcached::OPT_SERIALIZER, $config->getSerializerOption());
        $memcached->setOption(\Memcached::OPT_PREFIX_KEY, $config->keyPrefix);
        $memcached->setOption(\Memcached::OPT_CONNECT_TIMEOUT, $config->connectTimeout);
        $memcached->setOption(\Memcached::OPT_POLL_TIMEOUT, $config->pollTimeout);
        $memcached->setOption(\Memcached::OPT_RECV_TIMEOUT, $config->recvTimeout);
        $memcached->setOption(\Memcached::OPT_SEND_TIMEOUT, $config->sendTimeout);

        foreach ($config->options as $key => $value) {
            $memcached->setOption($key, $value);
        }

        if ($config->saslAuth && $config->username && $config->password) {
            $memcached->setSaslAuthData($config->username, $config->password);
        }

        return $memcached;
    }
}
