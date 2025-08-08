<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO that encapsulates all feature toggles for the cache system.
 *
 * This includes settings for stampede protection, metrics, events, encryption,
 * and other behavioral flags that can be enabled or disabled globally.
 */
final class FeatureConfig implements ImmutableValue
{
    /**
     * @param bool $stampedeProtection enables protection against cache stampedes (dogpiling)
     * @param bool $metricsEnabled enables the collection of performance and usage metrics
     * @param bool $eventsEnabled enables the dispatching of PSR-14 cache events
     * @param bool $strictValidation enables strict validation of keys and values
     * @param bool $compressionEnabled enables transparent GZIP compression for serialized values
     * @param bool $asyncOperations enables support for asynchronous cache operations (driver-dependent)
     * @param bool $debugMode enables verbose logging and additional debug information
     * @param bool $encryptionEnabled enables transparent AES-256-GCM encryption for values
     * @param string|null $encryptionKey the 32-byte key used for encryption if enabled
     */
    private function __construct(
        public readonly bool $stampedeProtection,
        public readonly bool $metricsEnabled,
        public readonly bool $eventsEnabled,
        public readonly bool $strictValidation,
        public readonly bool $compressionEnabled,
        public readonly bool $asyncOperations,
        public readonly bool $debugMode,
        public readonly bool $encryptionEnabled,
        public readonly ?string $encryptionKey
    ) {
    }

    /**
     * Creates an instance from a configuration array.
     *
     * @param array<string, mixed> $config the raw configuration array
     */
    public static function fromConfig(array $config = []): self
    {
        return new self(
            stampedeProtection: (bool) ($config['stampede_protection'] ?? true),
            metricsEnabled: (bool) ($config['metrics_enabled'] ?? true),
            eventsEnabled: (bool) ($config['events_enabled'] ?? true),
            strictValidation: (bool) ($config['strict_validation'] ?? true),
            compressionEnabled: (bool) ($config['compression_enabled'] ?? true),
            asyncOperations: (bool) ($config['async_operations'] ?? false),
            debugMode: (bool) ($config['debug_mode'] ?? false),
            encryptionEnabled: (bool) ($config['encryption_enabled'] ?? false),
            encryptionKey: isset($config['encryption_key']) ? (string) $config['encryption_key'] : null
        );
    }
}
