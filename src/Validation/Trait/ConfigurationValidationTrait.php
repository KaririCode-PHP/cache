<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Provides configuration validation functionality.
 *
 * This trait validates cache configuration parameters to ensure
 * they meet expected types and constraints.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
trait ConfigurationValidationTrait
{
    /**
     * Validates cache configuration.
     *
     * @param array<string, mixed> $config Configuration array
     *
     * @throws InvalidArgumentException if configuration is invalid
     */
    protected function validateConfiguration(array $config = []): void
    {
        $config = $config ?: ($this->config ?? []);

        // Validate name
        if (isset($config['name']) && !is_string($config['name'])) {
            throw InvalidArgumentException::invalidConfiguration('Configuration "name" must be a string, ' . gettype($config['name']) . ' given');
        }

        // Validate TTL
        if (array_key_exists('ttl', $config)) {
            $ttl = $config['ttl'];
            if (!($ttl instanceof \DateInterval || is_int($ttl) || is_null($ttl))) {
                throw InvalidArgumentException::invalidConfiguration('Configuration "ttl" must be DateInterval, int, or null');
            }

            if (is_int($ttl) && $ttl < 0) {
                throw InvalidArgumentException::invalidConfiguration('Configuration "ttl" cannot be negative');
            }
        }

        // Validate tags
        if (isset($config['tags'])) {
            if (!is_array($config['tags'])) {
                throw InvalidArgumentException::invalidConfiguration('Configuration "tags" must be an array');
            }
            $this->validateTagStrings($config['tags']);
        }

        // Validate topics
        if (isset($config['topics'])) {
            if (!is_array($config['topics'])) {
                throw InvalidArgumentException::invalidConfiguration('Configuration "topics" must be an array');
            }
            $this->validateTopicStrings($config['topics']);
        }

        // Validate prefix
        if (isset($config['prefix']) && !is_string($config['prefix'])) {
            throw InvalidArgumentException::invalidConfiguration('Configuration "prefix" must be a string');
        }

        // Validate namespace separator
        if (isset($config['namespace_separator']) && !is_string($config['namespace_separator'])) {
            throw InvalidArgumentException::invalidConfiguration('Configuration "namespace_separator" must be a string');
        }
    }
}
