<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Component\RedisLuaScriptManager;
use KaririCode\Cache\Driver\Redis\Component\RedisMaintenanceService;
use KaririCode\Cache\Driver\Redis\Component\RedisMonitoringService;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Driver\Redis\Operation\RedisAtomicOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisBatchOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisCrudOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisTagOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisTopicOperations;
use KaririCode\Cache\Driver\RedisDriver;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\Logging\Logger;

/**
 * Factory for creating a fully-configured RedisDriver.
 *
 * This factory handles the complex creation of a RedisDriver with all its
 * dependencies properly configured. It composes the final driver object from
 * specialized operation handlers and core components.
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
final class RedisDriverFactory extends AbstractDriverFactory
{
    private const REQUIRED_EXTENSION = 'redis';

    /**
     * Creates the RedisDriver instance.
     *
     * Assembles the RedisDriver by creating and injecting all its dependencies,
     * such as the Redis client, connection manager, and various operation handlers.
     *
     * @param array<string, mixed> $options the validated driver configuration
     * @param DriverManager $manager the driver manager for resolving shared dependencies
     * @param ExpirationProvider $expirationProvider the centrally configured expiration provider
     * @param Logger $logger the logger instance
     *
     * @return CacheDriver the fully configured RedisDriver instance
     */
    protected function doCreateDriver(
        array $options,
        DriverManager $manager,
        ExpirationProvider $expirationProvider,
        Logger $logger
    ): CacheDriver {
        $config = RedisConfig::fromArray($options);

        // Build Core Components
        $redis = new \Redis();
        $errorHandler = new RedisErrorHandler($logger, $config);
        $connectionManager = new RedisConnectionManager($redis, $config, $logger);
        $luaScriptManager = new RedisLuaScriptManager($redis);
        $monitoringService = new RedisMonitoringService($redis, $logger);
        $maintenanceService = new RedisMaintenanceService($redis, $config, $logger);

        // Configure the Redis instance itself
        $this->configureRedisInstance($redis, $config, $connectionManager, $manager);

        // Build Operation Handlers, injecting the ExpirationProvider
        $crudOperations = new RedisCrudOperations(
            $redis,
            $config,
            $errorHandler,
            $connectionManager,
            $monitoringService,
            $expirationProvider
        );

        $batchOperations = new RedisBatchOperations(
            $redis,
            $config,
            $errorHandler,
            $connectionManager,
            $expirationProvider
        );

        $atomicOperations = new RedisAtomicOperations(
            $redis,
            $config,
            $errorHandler,
            $connectionManager,
            $luaScriptManager,
            $expirationProvider
        );

        $tagOperations = new RedisTagOperations(
            $redis,
            $config,
            $errorHandler,
            $connectionManager,
            $expirationProvider // Assuming Lua is not needed here based on previous code
        );

        $topicOperations = new RedisTopicOperations(
            $redis,
            $config,
            $errorHandler,
            $connectionManager
            // Note: TopicOperations for Redis might not need expiration provider. Pass it if needed.
        );

        // Assemble the final driver
        return new RedisDriver(
            $crudOperations,
            $batchOperations,
            $atomicOperations,
            $tagOperations,
            $topicOperations,
            $maintenanceService,
            $monitoringService,
            $connectionManager,
            $config,
            $logger
        );
    }

    /**
     * Returns the unique name of the driver.
     *
     * @return string the driver's name
     */
    protected function getDriverName(): string
    {
        return 'redis';
    }

    /**
     * Specifies the configuration options required for this driver.
     *
     * @return array<string> a list of required option keys
     */
    protected function getRequiredOptions(): array
    {
        return ['host'];
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
        // Validation is handled by the RedisConfig DTO.
        RedisConfig::fromArray($options);

        return true;
    }

    /**
     * Validates the runtime environment prerequisites for the Redis driver.
     *
     * @throws ConfigurationException if the required 'redis' extension is not loaded or is outdated
     */
    protected function validatePrerequisites(): void
    {
        $this->validateExtension(self::REQUIRED_EXTENSION);

        $version = phpversion('redis');
        if ($version && version_compare($version, '5.0.0', '<')) {
            throw ConfigurationException::invalidConfiguration('redis', 'Redis extension version 5.0.0 or higher is required');
        }
    }

    /**
     * Configures the \Redis instance with options from the config DTO.
     *
     * @param \Redis $redis the Redis client instance
     * @param RedisConfig $config the configuration DTO
     * @param RedisConnectionManager $connectionManager the connection manager
     * @param DriverManager $manager the main driver manager
     */
    private function configureRedisInstance(
        \Redis $redis,
        RedisConfig $config,
        RedisConnectionManager $connectionManager,
        DriverManager $manager
    ): void {
        $connectionManager->ensureConnection();

        $serializer = $this->resolveSerializer([], $manager);
        if (null !== $serializer) {
            // Configure Redis serialization based on our serializer
            if (str_contains(get_class($serializer), 'Igbinary')) {
                $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_IGBINARY);
            } else {
                $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            }
        }

        if (!empty($config->keyPrefix)) {
            $redis->setOption(\Redis::OPT_PREFIX, $config->keyPrefix);
        }

        $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
    }
}
