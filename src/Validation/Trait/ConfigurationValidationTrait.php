<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Provides cache configuration validation functionality.
 * This trait validates cache configuration parameters to ensure
 * they meet expected types and constraints.
 */
trait ConfigurationValidationTrait
{
    /**
     * Validates a cache configuration array by delegating to specific validation methods.
     * This method acts as a high-level coordinator, improving readability.
     *
     * @param array<string, mixed> $config configuration array to validate
     *
     * @throws InvalidArgumentException if configuration is invalid
     */
    protected function validateConfiguration(array $config = []): void
    {
        $this->validateNameConfig($config);
        $this->validateTtlConfig($config);
        $this->validateTagsConfig($config);
        $this->validateTopicsConfig($config);
        $this->validatePrefixConfig($config);
        $this->validateNamespaceSeparatorConfig($config);
    }

    /**
     * Validates the 'name' configuration key.
     *
     * @param array<string, mixed> $config
     */
    private function validateNameConfig(array $config): void
    {
        if (isset($config['name']) && !is_string($config['name'])) {
            throw InvalidArgumentException::invalidConfiguration('Configuration "name" must be a string, ' . gettype($config['name']) . ' given');
        }
    }

    /**
     * Validates the 'ttl' configuration key.
     *
     * @param array<string, mixed> $config
     */
    private function validateTtlConfig(array $config): void
    {
        if (!array_key_exists('ttl', $config)) {
            return;
        }

        $ttl = $config['ttl'];
        if (!($ttl instanceof \DateInterval || is_int($ttl) || is_null($ttl))) {
            throw InvalidArgumentException::invalidConfiguration('Configuration "ttl" must be DateInterval, int, or null');
        }

        if (is_int($ttl) && $ttl < 0) {
            throw InvalidArgumentException::invalidConfiguration('Configuration "ttl" cannot be negative');
        }
    }

    /**
     * Validates the 'tags' configuration key.
     *
     * @param array<string, mixed> $config
     */
    private function validateTagsConfig(array $config): void
    {
        if (!isset($config['tags'])) {
            return;
        }

        if (!is_array($config['tags'])) {
            throw InvalidArgumentException::invalidConfiguration('Configuration "tags" must be an array');
        }
        $this->validateTagsConfig($config['tags']);
    }

    /**
     * Validates the 'topics' configuration key.
     *
     * @param array<string, mixed> $config
     */
    private function validateTopicsConfig(array $config): void
    {
        if (!isset($config['topics'])) {
            return;
        }

        if (!is_array($config['topics'])) {
            throw InvalidArgumentException::invalidConfiguration('Configuration "topics" must be an array');
        }
        $this->validateTopicsConfig($config['topics']);
    }

    /**
     * Validates the 'prefix' configuration key.
     *
     * @param array<string, mixed> $config
     */
    private function validatePrefixConfig(array $config): void
    {
        if (isset($config['prefix']) && !is_string($config['prefix'])) {
            throw InvalidArgumentException::invalidConfiguration('Configuration "prefix" must be a string');
        }
    }

    /**
     * Validates the 'namespace_separator' configuration key.
     *
     * @param array<string, mixed> $config
     */
    private function validateNamespaceSeparatorConfig(array $config): void
    {
        if (isset($config['namespace_separator']) && !is_string($config['namespace_separator'])) {
            throw InvalidArgumentException::invalidConfiguration('Configuration "namespace_separator" must be a string');
        }
    }
}
