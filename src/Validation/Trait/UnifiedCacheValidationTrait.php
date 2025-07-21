<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Unified validation trait that combines all validation concerns.
 *
 * This trait provides a single point of entry for all cache-related
 * validations, implementing the Facade pattern for validation operations.
 * It follows the Principle of Least Astonishment by providing a simple,
 * consistent interface.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
trait UnifiedCacheValidationTrait
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TagValidationTrait;
    use TopicValidationTrait;
    use ConfigurationValidationTrait;

    /**
     * Comprehensive validation for cache operations.
     *
     * This method implements the Single Level of Abstraction principle
     * by validating all aspects of a cache operation at once.
     *
     * @param string $key The cache key
     * @param mixed $value The cache value
     * @param array<\KaririCode\Cache\ValueObject\Tag> $tags Array of tags
     * @param array<\KaririCode\Cache\ValueObject\Topic> $topics Array of topics
     * @param array<string, mixed> $config Configuration array
     *
     * @throws InvalidArgumentException if any parameter is invalid
     */
    protected function validateCacheInput(
        string $key,
        mixed $value,
        array $tags = [],
        array $topics = [],
        array $config = []
    ): void {
        // Validate core parameters (fail fast)
        $this->validateKey($key);
        $this->validateValue($value);

        // Validate optional parameters
        if (!empty($tags)) {
            $this->validateTags($tags);
        }

        if (!empty($topics)) {
            $this->validateTopics($topics);
        }

        if (!empty($config)) {
            $this->validateConfiguration($config);
        }
    }

    /**
     * Validates batch cache operations.
     *
     * @param array<string, mixed> $items Key-value pairs
     * @param array<string, mixed> $config Configuration array
     *
     * @throws InvalidArgumentException if any item is invalid
     */
    protected function validateBatchCacheInput(array $items, array $config = []): void
    {
        $this->validateMultipleValues($items);

        if (!empty($config)) {
            $this->validateConfiguration($config);
        }
    }

    /**
     * Quick validation for read operations (key-only).
     *
     * @param string $key The cache key to validate
     *
     * @throws InvalidArgumentException if key is invalid
     */
    protected function validateCacheRead(string $key): void
    {
        $this->validateKey($key);
    }

    /**
     * Validates increment/decrement operations.
     *
     * @param string $key The cache key
     * @param int $step The increment/decrement step
     *
     * @throws InvalidArgumentException if parameters are invalid
     */
    protected function validateCacheIncrement(string $key, int $step = 1): void
    {
        $this->validateKey($key);
        $this->validateStep($step);
    }
}
