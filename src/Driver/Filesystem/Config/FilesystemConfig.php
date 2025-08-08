<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Config;

use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO for all FilesystemDriver configurations.
 *
 * This class acts as a Value Object, encapsulating all settings related to the
 * filesystem cache. It provides type safety, default values, and self-validation,
 * ensuring that the driver is always configured correctly.
 */
final class FilesystemConfig implements ImmutableValue
{
    private const DEFAULT_KEY_PREFIX = 'cache_';
    private const DEFAULT_MAX_SIZE_BYTES = 104857600; // 100MB
    private const DEFAULT_CLEANUP_PROBABILITY = 0.01;
    private const DEFAULT_DIRECTORY_LEVELS = 2;
    private const DEFAULT_FILE_PERMISSIONS = 0644;
    private const DEFAULT_DIRECTORY_PERMISSIONS = 0755;
    private const DEFAULT_MAX_KEY_LENGTH = 250;
    private const DEFAULT_MAX_VALUE_SIZE_BYTES = 2097152; // 2MB

    /**
     * @param string $directory the root directory for cache files
     * @param string $keyPrefix a prefix for all cache filenames
     * @param int $maxSize the maximum total size of the cache directory in bytes
     * @param float $cleanupProbability The probability (0.0 to 1.0) of garbage collection running.
     * @param bool $useLocking whether to use file locking to prevent race conditions
     * @param bool $useCompression whether to compress cache data
     * @param int $directoryLevels the number of subdirectory levels for hashing
     * @param int $filePermissions the octal permissions for new cache files
     * @param int $directoryPermissions the octal permissions for new cache directories
     * @param int $maxKeyLength the maximum allowed length for a cache key
     * @param int $maxValueSize the maximum allowed size for a single serialized value in bytes
     */
    private function __construct(
        public readonly string $directory,
        public readonly string $keyPrefix,
        public readonly int $maxSize,
        public readonly float $cleanupProbability,
        public readonly bool $useLocking,
        public readonly bool $useCompression,
        public readonly int $directoryLevels,
        public readonly int $filePermissions,
        public readonly int $directoryPermissions,
        public readonly int $maxKeyLength,
        public readonly int $maxValueSize
    ) {
        $this->validate();
    }

    /**
     * Creates an instance from a configuration array.
     *
     * @param array<string, mixed> $config the raw configuration array
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            directory: (string) ($config['directory'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kariricode_cache'),
            keyPrefix: (string) ($config['key_prefix'] ?? self::DEFAULT_KEY_PREFIX),
            maxSize: (int) ($config['max_size'] ?? self::DEFAULT_MAX_SIZE_BYTES),
            cleanupProbability: (float) ($config['cleanup_probability'] ?? self::DEFAULT_CLEANUP_PROBABILITY),
            useLocking: (bool) ($config['use_locking'] ?? true),
            useCompression: (bool) ($config['use_compression'] ?? false),
            directoryLevels: (int) ($config['directory_levels'] ?? self::DEFAULT_DIRECTORY_LEVELS),
            filePermissions: (int) ($config['file_permissions'] ?? self::DEFAULT_FILE_PERMISSIONS),
            directoryPermissions: (int) ($config['directory_permissions'] ?? self::DEFAULT_DIRECTORY_PERMISSIONS),
            maxKeyLength: (int) ($config['max_key_length'] ?? self::DEFAULT_MAX_KEY_LENGTH),
            maxValueSize: (int) ($config['max_value_size'] ?? self::DEFAULT_MAX_VALUE_SIZE_BYTES)
        );
    }

    /**
     * Validates the configuration properties to ensure they are logical.
     * This method acts as a coordinator for all individual validation checks.
     */
    private function validate(): void
    {
        $this->ensureDirectoryIsNotEmpty();
        $this->ensureMaxSizeIsAboveMinimum();
        $this->ensureCleanupProbabilityIsWithinRange();
        $this->ensureDirectoryLevelsAreWithinRange();
        $this->ensureMaxKeyLengthIsPositive();
        $this->ensureMaxValueSizeIsPositive();
    }

    /** @throws ConfigurationException */
    private function ensureDirectoryIsNotEmpty(): void
    {
        if (empty($this->directory)) {
            throw ConfigurationException::invalidConfiguration('directory', 'Cache directory path cannot be empty.');
        }
    }

    /** @throws ConfigurationException */
    private function ensureMaxSizeIsAboveMinimum(): void
    {
        if ($this->maxSize < 1024) { // 1KB minimum
            throw ConfigurationException::invalidOption('max_size', $this->maxSize, 'an integer greater than 1024');
        }
    }

    /** @throws ConfigurationException */
    private function ensureCleanupProbabilityIsWithinRange(): void
    {
        if ($this->cleanupProbability < 0.0 || $this->cleanupProbability > 1.0) {
            throw ConfigurationException::invalidOption('cleanup_probability', $this->cleanupProbability, 'a float between 0.0 and 1.0');
        }
    }

    /** @throws ConfigurationException */
    private function ensureDirectoryLevelsAreWithinRange(): void
    {
        if ($this->directoryLevels < 0 || $this->directoryLevels > 4) {
            throw ConfigurationException::invalidOption('directory_levels', $this->directoryLevels, 'an integer between 0 and 4');
        }
    }

    /** @throws ConfigurationException */
    private function ensureMaxKeyLengthIsPositive(): void
    {
        if ($this->maxKeyLength <= 0) {
            throw ConfigurationException::invalidOption('max_key_length', $this->maxKeyLength, 'a positive integer');
        }
    }

    /** @throws ConfigurationException */
    private function ensureMaxValueSizeIsPositive(): void
    {
        if ($this->maxValueSize <= 0) {
            throw ConfigurationException::invalidOption('max_value_size', $this->maxValueSize, 'a positive integer');
        }
    }
}
