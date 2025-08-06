<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * Feature Configuration DTO.
 *
 * This immutable DTO encapsulates all feature toggles for the cache system,
 * including stampede protection, metrics, events, and encryption settings.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class FeatureConfig implements ImmutableValue
{
    public readonly bool $stampedeProtection;
    public readonly bool $metricsEnabled;
    public readonly bool $eventsEnabled;
    public readonly bool $strictValidation;
    public readonly bool $compressionEnabled;
    public readonly bool $asyncOperations;
    public readonly bool $debugMode;
    public readonly bool $encryptionEnabled;
    public readonly ?string $encryptionKey;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->stampedeProtection = (bool) ($config['stampede_protection'] ?? true);
        $this->metricsEnabled = (bool) ($config['metrics_enabled'] ?? true);
        $this->eventsEnabled = (bool) ($config['events_enabled'] ?? true);
        $this->strictValidation = (bool) ($config['strict_validation'] ?? true);
        $this->compressionEnabled = (bool) ($config['compression_enabled'] ?? true);
        $this->asyncOperations = (bool) ($config['async_operations'] ?? false);
        $this->debugMode = (bool) ($config['debug_mode'] ?? false);
        $this->encryptionEnabled = (bool) ($config['encryption_enabled'] ?? false);
        $this->encryptionKey = isset($config['encryption_key']) ? (string) $config['encryption_key'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stampede_protection' => $this->stampedeProtection,
            'metrics_enabled' => $this->metricsEnabled,
            'events_enabled' => $this->eventsEnabled,
            'strict_validation' => $this->strictValidation,
            'compression_enabled' => $this->compressionEnabled,
            'async_operations' => $this->asyncOperations,
            'debug_mode' => $this->debugMode,
            'encryption_enabled' => $this->encryptionEnabled,
            'encryption_key' => $this->encryptionKey,
        ];
    }
}
