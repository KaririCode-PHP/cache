<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\DriverManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\Logging\Logger;

/**
 * Abstract base factory for cache drivers.
 *
 * This class provides common functionality for all driver factories,
 * including ExpirationProvider creation, logger resolution, and
 * standard validation methods.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 */
abstract class AbstractDriverFactory implements CacheDriverFactory
{
    /**
     * Create the cache driver instance.
     *
     * Template method that orchestrates driver creation:
     * 1. Validates prerequisites
     * 2. Creates ExpirationProvider
     * 3. Resolves logger
     * 4. Delegates to concrete implementation
     *
     * @param array<string, mixed> $options Driver configuration options
     * @param DriverManager $manager Driver manager for dependencies
     *
     * @throws ConfigurationException If configuration is invalid
     *
     * @return CacheDriver The created cache driver
     */
    public function createDriver(array $options, DriverManager $manager): CacheDriver
    {
        // Validate prerequisites
        $this->validatePrerequisites();

        // Validate options
        if (!$this->validateOptions($options)) {
            throw ConfigurationException::invalidConfiguration($this->getDriverName(), 'Invalid driver options provided');
        }

        // Create or resolve ExpirationProvider
        $expirationProvider = $this->getExpirationProviderFromOptions($options);

        // Resolve logger
        $logger = $this->resolveLogger($options, $manager);

        // Create the driver
        return $this->doCreateDriver(
            $options,
            $manager,
            $expirationProvider,
            $logger
        );
    }

    /**
     * Validate driver options.
     *
     * @param array<string, mixed> $options Options to validate
     *
     * @return bool True if valid
     */
    public function validateOptions(array $options): bool
    {
        $requiredOptions = $this->getRequiredOptions();

        foreach ($requiredOptions as $option) {
            if (!isset($options[$option]) || empty($options[$option])) {
                return false;
            }
        }

        return $this->doValidateOptions($options);
    }

    /**
     * Get supported options for this driver.
     *
     * @return array<string> List of supported option names
     */
    public function getSupportedOptions(): array
    {
        return array_merge(
            $this->getRequiredOptions(),
            $this->getOptionalOptions(),
            [
                'expiration_provider',
                'logger',
                'serializer',
                'metrics_collector',
                'event_dispatcher',
            ]
        );
    }

    /**
     * Create the actual driver instance.
     *
     * This method must be implemented by concrete factories.
     *
     * @param array<string, mixed> $options Validated options
     * @param DriverManager $manager Driver manager
     * @param ExpirationProvider $expirationProvider Expiration provider
     * @param Logger $logger Logger instance
     *
     * @return CacheDriver The created driver
     */
    abstract protected function doCreateDriver(
        array $options,
        DriverManager $manager,
        ExpirationProvider $expirationProvider,
        Logger $logger
    ): CacheDriver;

    /**
     * Get the driver name.
     *
     * @return string Driver name for error messages
     */
    abstract protected function getDriverName(): string;

    /**
     * Get required options for this driver.
     *
     * @return array<string> List of required option names
     */
    abstract protected function getRequiredOptions(): array;

    /**
     * Get optional options for this driver.
     *
     * @return array<string> List of optional option names
     */
    protected function getOptionalOptions(): array
    {
        return [];
    }

    /**
     * Perform driver-specific option validation.
     *
     * @param array<string, mixed> $options Options to validate
     *
     * @return bool True if valid
     */
    protected function doValidateOptions(array $options): bool
    {
        return true;
    }

    /**
     * Validate prerequisites for the driver.
     *
     * @throws ConfigurationException If prerequisites not met
     */
    protected function validatePrerequisites(): void
    {
        // Override in concrete classes if needed
    }

    /**
     * Extracts the pre-configured ExpirationProvider from the options.
     * This method now ENFORCES that the provider is passed in, removing redundant logic.
     */
    protected function getExpirationProviderFromOptions(array $options): ExpirationProvider
    {
        if (isset($options['expiration_provider']) && $options['expiration_provider'] instanceof ExpirationProvider) {
            return $options['expiration_provider'];
        }

        // Força o chamador (CacheBuilder) a sempre fornecer a instância.
        throw ConfigurationException::missingConfiguration('A pre-configured ExpirationProvider instance must be passed to the driver factory under the "expiration_provider" key.');
    }

    /**
     * Resolve logger from options or manager.
     *
     * @param array<string, mixed> $options Driver options
     * @param DriverManager $manager Driver manager
     *
     * @return Logger The logger instance
     */
    protected function resolveLogger(
        array $options,
        DriverManager $manager
    ): ?Logger {
        // Check for explicit logger
        if (isset($options['logger'])
            && $options['logger'] instanceof Logger) {
            return $options['logger'];
        }

        // Check manager for logger
        if (method_exists($manager, 'getLogger')) {
            $logger = $manager->getLogger();
            if ($logger instanceof Logger) {
                return $logger;
            }
        }

        // Return null logger as fallback
        return null;
    }

    /**
     * Resolve serializer from options or manager.
     *
     * @param array<string, mixed> $options Driver options
     * @param DriverManager $manager Driver manager
     *
     * @return CacheSerializer|null The serializer or null
     */
    protected function resolveSerializer(
        array $options,
        DriverManager $manager
    ): ?CacheSerializer {
        // Check for explicit serializer
        if (isset($options['serializer'])
            && $options['serializer'] instanceof CacheSerializer) {
            return $options['serializer'];
        }

        // Get from manager
        return $manager->getSerializer();
    }

    /**
     * Get default configuration for the driver.
     *
     * @return array<string, mixed> Default configuration
     */
    protected function getDefaultOptions(): array
    {
        return [
            'default_ttl' => 3600,
            'operation_ttls' => [
                'set' => 3600,
                'setMultiple' => 3600,
                'add' => 3600,
                'increment' => 7200,
                'decrement' => 7200,
                'setWithTags' => 1800,
                'compareAndSwap' => 1800,
            ],
            'key_pattern_ttls' => [
                'session:*' => 7200,
                'api:*' => 1800,
                'temp:*' => 300,
                'user:*' => 900,
                'config:*' => 3600,
            ],
            'sliding_expiration' => false,
            'max_ttl' => 2592000, // 30 days
        ];
    }

    /**
     * Merge options with defaults.
     *
     * @param array<string, mixed> $options User options
     *
     * @return array<string, mixed> Merged options
     */
    protected function mergeWithDefaults(array $options): array
    {
        $defaults = $this->getDefaultOptions();

        // Deep merge for nested arrays
        foreach (['operation_ttls', 'key_pattern_ttls'] as $key) {
            if (isset($options[$key]) && is_array($options[$key])) {
                $defaults[$key] = array_merge(
                    $defaults[$key],
                    $options[$key]
                );
                unset($options[$key]);
            }
        }

        return array_merge($defaults, $options);
    }

    /**
     * Validate port number.
     *
     * @param int $port Port number to validate
     * @param string $name Option name for error message
     *
     * @throws ConfigurationException If port is invalid
     */
    protected function validatePort(int $port, string $name = 'port'): void
    {
        if ($port < 1 || $port > 65535) {
            throw ConfigurationException::invalidConfiguration($name, 'Port must be between 1 and 65535');
        }
    }

    /**
     * Validate timeout value.
     *
     * @param float $timeout Timeout in seconds
     * @param string $name Option name for error message
     * @param float $min Minimum timeout
     * @param float $max Maximum timeout
     *
     * @throws ConfigurationException If timeout is invalid
     */
    protected function validateTimeout(
        float $timeout,
        string $name = 'timeout',
        float $min = 0.0,
        float $max = 300.0
    ): void {
        if ($timeout < $min || $timeout > $max) {
            throw ConfigurationException::invalidConfiguration($name, sprintf('Timeout must be between %.1f and %.1f seconds', $min, $max));
        }
    }

    /**
     * Validate extension availability.
     *
     * @param string $extension Extension name
     *
     * @throws ConfigurationException If extension not loaded
     */
    protected function validateExtension(string $extension): void
    {
        if (!extension_loaded($extension)) {
            throw ConfigurationException::missingExtension($extension);
        }
    }
}
