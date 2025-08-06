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
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class FilesystemConfig implements ImmutableValue
{
    /**
     * @param string $directory the root directory for cache files
     * @param string $keyPrefix a prefix for all cache filenames
     * @param int $maxSize the maximum total size of the cache directory in bytes
     * @param float $cleanupProbability The probability (0.0 to 1.0) of garbage collection running on a 'set' operation.
     * @param bool $useLocking whether to use file locking to prevent race conditions
     * @param bool $useCompression whether to compress cache data
     * @param int $directoryLevels the number of subdirectory levels to create for better performance
     * @param int $filePermissions the octal permissions for new cache files
     * @param int $directoryPermissions the octal permissions for new cache directories
     * @param int $maxKeyLength the maximum allowed length for a cache key
     * @param int $maxValueSize the maximum allowed size for a single serialized cache value in bytes
     */
    public function __construct(
        public readonly string $directory,
        public readonly string $keyPrefix = 'cache_',
        public readonly int $maxSize = 104857600, // 100MB
        public readonly float $cleanupProbability = 0.01,
        public readonly bool $useLocking = true,
        public readonly bool $useCompression = false,
        public readonly int $directoryLevels = 2,
        public readonly int $filePermissions = 0644,
        public readonly int $directoryPermissions = 0755,
        public readonly int $maxKeyLength = 250,
        public readonly int $maxValueSize = 2097152 // 2MB
    ) {
        $this->validate();
    }

    /**
     * Creates an instance from a configuration array.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            directory: $config['directory'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kariricode_cache',
            keyPrefix: $config['key_prefix'] ?? 'cache_',
            maxSize: (int) ($config['max_size'] ?? 104857600),
            cleanupProbability: (float) ($config['cleanup_probability'] ?? 0.01),
            useLocking: (bool) ($config['use_locking'] ?? true),
            useCompression: (bool) ($config['use_compression'] ?? false),
            directoryLevels: (int) ($config['directory_levels'] ?? 2),
            filePermissions: (int) ($config['file_permissions'] ?? 0644),
            directoryPermissions: (int) ($config['directory_permissions'] ?? 0755),
            maxKeyLength: (int) ($config['max_key_length'] ?? 250),
            maxValueSize: (int) ($config['max_value_size'] ?? 2097152)
        );
    }

    /**
     * Validates the configuration properties.
     *
     * @throws ConfigurationException if any configuration is invalid
     */
    private function validate(): void
    {
        if (empty($this->directory)) {
            throw ConfigurationException::invalidConfiguration('directory', 'Cache directory path cannot be empty.');
        }
        if ($this->maxSize < 1024) { // 1KB minimum
            throw ConfigurationException::invalidOption('max_size', $this->maxSize, 'an integer greater than 1024');
        }
        if ($this->cleanupProbability < 0.0 || $this->cleanupProbability > 1.0) {
            throw ConfigurationException::invalidOption('cleanup_probability', $this->cleanupProbability, 'a float between 0.0 and 1.0');
        }
        if ($this->directoryLevels < 0 || $this->directoryLevels > 4) {
            throw ConfigurationException::invalidOption('directory_levels', $this->directoryLevels, 'an integer between 0 and 4');
        }
        if ($this->maxKeyLength <= 0) {
            throw ConfigurationException::invalidOption('max_key_length', $this->maxKeyLength, 'a positive integer');
        }
        if ($this->maxValueSize <= 0) {
            throw ConfigurationException::invalidOption('max_value_size', $this->maxValueSize, 'a positive integer');
        }
    }

    /**
     * Converts the DTO back to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'directory' => $this->directory,
            'key_prefix' => $this->keyPrefix,
            'max_size' => $this->maxSize,
            'cleanup_probability' => $this->cleanupProbability,
            'use_locking' => $this->useLocking,
            'use_compression' => $this->useCompression,
            'directory_levels' => $this->directoryLevels,
            'file_permissions' => $this->filePermissions,
            'directory_permissions' => $this->directoryPermissions,
            'max_key_length' => $this->maxKeyLength,
            'max_value_size' => $this->maxValueSize,
        ];
    }
}
