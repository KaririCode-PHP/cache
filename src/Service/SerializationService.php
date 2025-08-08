<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Serializer\IgbinarySerializer;
use KaririCode\Cache\Serializer\JsonSerializer;
use KaririCode\Cache\Serializer\NativeSerializer;
use KaririCode\Contract\ImmutableValue;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Comprehensive serialization service for cache operations.
 *
 * This service acts as a central registry and facade for multiple serialization
 * strategies, providing a unified interface for serialize/deserialize operations.
 * It implements the Registry, Facade, and Strategy patterns to manage different
 * serialization backends while maintaining a consistent API.
 *
 * Key Features:
 * - Multiple serialization backends (native, JSON, Igbinary)
 * - Automatic fallback to default serializer
 * - Comprehensive error handling with logging
 * - Runtime serializer registration
 * - Delegated CacheSerializer interface implementation
 * - Performance optimization through serializer caching
 *
 * Design Patterns:
 * - Registry Pattern: Maintains collection of available serializers
 * - Facade Pattern: Provides simplified interface to complex serialization subsystem
 * - Strategy Pattern: Allows runtime selection of serialization algorithms
 * - Decorator Pattern: Can wrap existing serializers with additional functionality
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see      https://kariricode.org/
 * @see       https://www.php-fig.org/psr/psr-3/ PSR-3 Logger Interface
 * @see       CacheSerializer The interface this service implements
 */
final class SerializationService implements CacheSerializer
{
    /**
     * Default serializer identifier.
     */
    private const DEFAULT_SERIALIZER = 'native';

    /**
     * Registry of available serializers indexed by name.
     *
     * @var array<string, CacheSerializer>
     */
    private array $serializers = [];

    /**
     * The name of the current default serializer.
     */
    private string $defaultSerializer = self::DEFAULT_SERIALIZER;

    /**
     * Constructs the serialization service with optional logging.
     *
     * @param LoggerInterface $logger PSR-3 logger for debugging and error tracking
     */
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        $this->registerDefaultSerializers();
    }

    /**
     * Registers a new serializer with a specific identifier.
     *
     * This method allows runtime registration of custom serializers,
     * enabling extensibility and customization of serialization behavior.
     *
     * @param string $name Unique identifier for the serializer
     * @param CacheSerializer $serializer The serializer instance to register
     *
     * @throws InvalidArgumentException When the name is empty or invalid
     */
    public function registerSerializer(string $name, CacheSerializer $serializer): void
    {
        $this->ensureSerializerNameIsValid($name);

        $this->serializers[$name] = $serializer;

        $this->logger->debug('Serializer registered successfully', [
            'serializer_name' => $name,
            'serializer_class' => get_class($serializer),
        ]);
    }

    /**
     * Sets the default serializer to be used when no specific serializer is requested.
     *
     * @param string $name The name of the registered serializer to set as default
     *
     * @throws InvalidArgumentException When the specified serializer is not registered
     */
    public function setDefaultSerializer(string $name): void
    {
        $this->ensureSerializerIsRegistered($name);

        $previousDefault = $this->defaultSerializer;
        $this->defaultSerializer = $name;

        $this->logger->info('Default serializer changed', [
            'previous_default' => $previousDefault,
            'new_default' => $name,
        ]);
    }

    /**
     * Gets the name of the current default serializer.
     *
     * @return string The default serializer identifier
     */
    public function getDefaultSerializer(): string
    {
        return $this->defaultSerializer;
    }

    /**
     * Gets a list of all registered serializer names.
     *
     * @return array<string> Array of registered serializer identifiers
     */
    public function getRegisteredSerializers(): array
    {
        return array_keys($this->serializers);
    }

    /**
     * Clears all registered serializers.
     */
    public function clearSerializers(): void
    {
        $this->serializers = [];
    }

    /**
     * Checks if a serializer with the given name is registered.
     *
     * @param string $name The serializer name to check
     *
     * @return bool True if the serializer is registered, false otherwise
     */
    public function hasSerializer(string $name): bool
    {
        return isset($this->serializers[$name]);
    }

    /**
     * Serializes a value using the specified or default serializer.
     *
     * This method provides the main serialization functionality with comprehensive
     * error handling and logging. It delegates to the appropriate serializer
     * implementation based on the provided serializer name.
     *
     * @param mixed $value The value to serialize
     * @param string|null $serializerName Optional serializer name (uses default if null)
     *
     * @throws CacheException When serialization fails
     *
     * @return string The serialized representation of the value
     */
    public function serialize(mixed $value, ?string $serializerName = null): string
    {
        $serializer = $this->getSerializer($serializerName);
        $serializerName = $serializerName ?? $this->defaultSerializer;

        try {
            $result = $serializer->serialize($value);

            $this->logger->debug('Value serialized successfully', [
                'serializer' => $serializerName,
                'value_type' => get_debug_type($value),
                'serialized_length' => strlen($result),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Serialization failed', [
                'serializer' => $serializerName,
                'value_type' => get_debug_type($value),
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            throw CacheException::serializationFailed(get_debug_type($value), $e);
        }
    }

    /**
     * Deserializes a string using the specified or default serializer.
     *
     * This method provides the main deserialization functionality with comprehensive
     * error handling and logging. It delegates to the appropriate serializer
     * implementation based on the provided serializer name.
     *
     * @param string $data The serialized data to deserialize
     * @param string|null $serializerName Optional serializer name (uses default if null)
     *
     * @throws CacheException When deserialization fails
     *
     * @return mixed The deserialized value
     */
    public function unserialize(string $data, ?string $serializerName = null): mixed
    {
        $serializer = $this->getSerializer($serializerName);
        $serializerName = $serializerName ?? $this->defaultSerializer;

        try {
            $result = $serializer->unserialize($data);

            $this->logger->debug('Data deserialized successfully', [
                'serializer' => $serializerName,
                'data_length' => strlen($data),
                'result_type' => get_debug_type($result),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Deserialization failed', [
                'serializer' => $serializerName,
                'data_length' => strlen($data),
                'data_preview' => substr($data, 0, 100) . (strlen($data) > 100 ? '...' : ''),
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            throw CacheException::deserializationFailed($serializerName, $e);
        }
    }

    // ========================================================================
    // CacheSerializer Interface Implementation (Delegation)
    // ========================================================================

    /**
     * Checks if a value can be serialized using the specified or default serializer.
     *
     * @param mixed $value The value to check for serializability
     * @param string|null $serializerName Optional serializer name
     *
     * @return bool True if the value can be serialized, false otherwise
     */
    public function canSerialize(mixed $value, ?string $serializerName = null): bool
    {
        if (!$this->isSerializationSupported($value)) {
            return false;
        }

        try {
            $serializer = $this->getSerializer($serializerName);

            return $serializer->canSerialize($value);
        } catch (\Throwable $e) {
            $this->logger->warning('Cannot check serializability due to serializer error', [
                'serializer' => $serializerName ?? $this->defaultSerializer,
                'value_type' => get_debug_type($value),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Gets the MIME type of the default serializer.
     *
     * @return string The MIME type string
     */
    public function getMimeType(): string
    {
        return $this->getSerializer()->getMimeType();
    }

    /**
     * Gets the configuration of the default serializer.
     *
     * @return ImmutableValue The serializer configuration
     */
    public function getConfiguration(): ImmutableValue
    {
        return $this->getSerializer()->getConfiguration();
    }

    /**
     * Retrieves a serializer instance by name.
     *
     * @param string|null $name The serializer name (uses default if null)
     *
     * @throws InvalidArgumentException When the requested serializer is not registered
     *
     * @return CacheSerializer The requested serializer instance
     */
    public function getSerializer(?string $name = null): CacheSerializer
    {
        $serializerName = $name ?? $this->defaultSerializer;

        $this->ensureSerializerIsRegistered($serializerName);

        return $this->serializers[$serializerName];
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    /**
     * Returns TRUE when the given value is serializable by the built‑in
     * serializers currently registered (native / json / igbinary).
     *
     * At the moment we block `Closure`, which none of those serializers can
     * safely encode. Add more checks here if new intrinsically non‑serializable
     * types must be filtered (e.g. Generator, Fiber, Resource…).
     */
    private function isSerializationSupported(mixed $value): bool
    {
        return !($value instanceof \Closure);
    }

    /**
     * Registers the default set of serializers available in the system.
     *
     * This method automatically detects and registers available serializers
     * based on installed PHP extensions and available classes.
     */
    private function registerDefaultSerializers(): void
    {
        // Native PHP serialization is always available
        $this->serializers['native'] = new NativeSerializer(allowedClasses: [\stdClass::class]);
        $this->logger->debug('Native serializer registered');

        // JSON serialization is always available in modern PHP
        $this->serializers['json'] = new JsonSerializer();
        $this->logger->debug('JSON serializer registered');

        // Igbinary is optional - only register if the extension is loaded
        if (extension_loaded('igbinary')) {
            $this->serializers['igbinary'] = new IgbinarySerializer();
            $this->logger->debug('Igbinary serializer registered');
        }
    }

    /**
     * Validates that a serializer name is not empty and contains valid characters.
     *
     * @param string $name The serializer name to validate
     *
     * @throws InvalidArgumentException When the name is empty or invalid
     */
    private function ensureSerializerNameIsValid(string $name): void
    {
        if (empty($name)) {
            throw InvalidArgumentException::invalidSerializer('Serializer name cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name)) {
            throw InvalidArgumentException::invalidSerializer(sprintf('Invalid serializer name "%s". Must start with letter and contain only alphanumeric characters and underscores.', $name));
        }
    }

    /**
     * Ensures that a serializer with the given name is registered.
     *
     * @param string $name The serializer name to check
     *
     * @throws InvalidArgumentException When the serializer is not registered
     */
    private function ensureSerializerIsRegistered(string $name): void
    {
        if (!isset($this->serializers[$name])) {
            throw InvalidArgumentException::invalidSerializer(sprintf('Serializer "%s" is not registered. Available serializers: %s', $name, implode(', ', array_keys($this->serializers))));
        }
    }
}
