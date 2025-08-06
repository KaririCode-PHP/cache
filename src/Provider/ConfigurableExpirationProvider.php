<?php

declare(strict_types=1);

namespace KaririCode\Cache\Provider;

use DateTimeInterface;
use KaririCode\Cache\Config\DTO\ExpirationProviderConfig;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Configurable implementation of ExpirationProvider with flexible TTL strategies.
 *
 * This provider supports:
 * - A default TTL for all operations.
 * - Operation-specific TTLs (e.g., for 'set', 'add', 'increment').
 * - Key pattern-based TTLs (e.g., for 'session:*', 'api:*').
 * - Sliding expiration, which resets an item's TTL upon access.
 * - Enforcement of a maximum allowed TTL.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ConfigurableExpirationProvider implements ExpirationProvider
{
    private readonly int $defaultTtl;
    private readonly array $operationTtls;
    private readonly array $keyPatternTtls;
    private readonly bool $slidingExpiration;
    private readonly ?int $maxTtl;

    /**
     * The constructor is made private to force instantiation via the named constructor.
     * This ensures that the object is always created from a well-defined DTO.
     */
    private function __construct(ExpirationProviderConfig $config)
    {
        $this->defaultTtl = $config->defaultTtl;
        $this->operationTtls = $config->operationTtls;
        $this->keyPatternTtls = $config->keyPatternTtls;
        $this->slidingExpiration = $config->slidingExpiration;
        $this->maxTtl = $config->maxTtl;

        $this->validateConfiguration();
    }

    /**
     * Creates an instance from a configuration DTO.
     * This is the single, public entry point for creating an instance.
     */
    public static function fromConfig(ExpirationProviderConfig $config): self
    {
        return new self($config);
    }

    /**
     * Calculates the definitive expiration time for a cache entry.
     *
     * The TTL is determined based on the following priority:
     * 1. Explicit TTL provided to the method.
     * 2. TTL matching a key pattern.
     * 3. TTL for the specific cache operation.
     * 4. The default TTL.
     *
     * @param \DateInterval|\DateTimeInterface|int|null $ttl the desired TTL
     * @param string|null $key the cache key, used for pattern matching
     * @param string|null $operation The operation type (e.g., 'set').
     * @param array<string, mixed> $context additional context for decision-making
     *
     * @return \DateTimeInterface|null the calculated expiration timestamp, or null for indefinite caching
     */
    public function calculateExpiration(
        \DateInterval|\DateTimeInterface|int|null $ttl = null,
        ?string $key = null,
        ?string $operation = null,
        array $context = []
    ): ?\DateTimeInterface {
        if (null !== $ttl) {
            return $this->processTtl($ttl);
        }

        if (null !== $key) {
            $patternTtl = $this->getKeyPatternTtl($key);
            if (null !== $patternTtl) {
                return $this->fromSeconds($patternTtl);
            }
        }

        if (null !== $operation) {
            $operationTtl = $this->getOperationTtl($operation);
            if (null !== $operationTtl) {
                return $this->fromSeconds($operationTtl);
            }
        }

        return $this->fromSeconds($this->defaultTtl);
    }

    /**
     * Gets the default TTL in seconds.
     *
     * @return int the default time-to-live
     */
    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Determines if a given expiration timestamp has passed.
     *
     * @param \DateTimeInterface|null $expiration the expiration timestamp to check
     *
     * @return bool true if the item has expired, false otherwise
     */
    public function isExpired(?\DateTimeInterface $expiration): bool
    {
        if (null === $expiration) {
            return false; // An item with no expiration is never considered expired.
        }

        return $expiration <= new \DateTimeImmutable();
    }

    /**
     * Converts various TTL formats into an integer representing seconds.
     *
     * @param \DateInterval|\DateTimeInterface|int|null $ttl the TTL value to convert
     *
     * @return int|null the TTL in seconds, or null for no expiration
     */
    public function toSeconds(\DateInterval|\DateTimeInterface|int|null $ttl): ?int
    {
        if (null === $ttl) {
            return null;
        }
        if (is_int($ttl)) {
            return $this->validateTtlSeconds($ttl);
        }
        if ($ttl instanceof \DateInterval) {
            $reference = new \DateTimeImmutable();
            $expiration = $reference->add($ttl);
            $seconds = $expiration->getTimestamp() - $reference->getTimestamp();

            return $this->validateTtlSeconds($seconds);
        }
        if ($ttl instanceof \DateTimeInterface) {
            $now = new \DateTimeImmutable();
            $seconds = $ttl->getTimestamp() - $now->getTimestamp();
            if ($seconds <= 0) {
                throw InvalidArgumentException::invalidTtl('Expiration time must be in the future');
            }

            return $this->validateTtlSeconds($seconds);
        }
        throw InvalidArgumentException::invalidTtl($ttl);
    }

    /**
     * Creates a DateTimeInterface object representing a future timestamp from a given number of seconds.
     *
     * @param int $seconds the number of seconds from now
     *
     * @return \DateTimeInterface the calculated expiration timestamp
     */
    public function fromSeconds(int $seconds): \DateTimeInterface
    {
        $validatedSeconds = $this->validateTtlSeconds($seconds);

        return new \DateTimeImmutable('+' . $validatedSeconds . ' seconds');
    }

    /**
     * Gets the configured TTL for a specific operation type.
     *
     * @param string $operation The operation name (e.g., 'set', 'increment').
     *
     * @return int|null the TTL in seconds, or null if not configured
     */
    public function getOperationTtl(string $operation): ?int
    {
        return $this->operationTtls[$operation] ?? null;
    }

    /**
     * Gets the configured TTL for a key that matches a specific pattern.
     *
     * @param string $key the cache key to check against patterns
     *
     * @return int|null the TTL in seconds, or null if no pattern matches
     */
    public function getKeyPatternTtl(string $key): ?int
    {
        foreach ($this->keyPatternTtls as $pattern => $ttl) {
            if ($this->matchesPattern($key, $pattern)) {
                return $ttl;
            }
        }

        return null;
    }

    /**
     * Checks if sliding expiration is enabled.
     *
     * @return bool true if sliding expiration is active
     */
    public function isSlidingExpiration(): bool
    {
        return $this->slidingExpiration;
    }

    /**
     * Calculates a new expiration timestamp if sliding expiration is enabled.
     *
     * @param \DateTimeInterface $currentExpiration the item's current expiration timestamp
     * @param string|null $key the cache key, for context
     * @param array<string, mixed> $context additional context
     *
     * @return \DateTimeInterface the updated expiration timestamp
     */
    public function updateSlidingExpiration(
        \DateTimeInterface $currentExpiration,
        ?string $key = null,
        array $context = []
    ): \DateTimeInterface {
        if (!$this->slidingExpiration) {
            return $currentExpiration;
        }
        $now = new \DateTimeImmutable();
        $originalTtl = $currentExpiration->getTimestamp() - $now->getTimestamp();
        if ($originalTtl <= 0) {
            return $currentExpiration; // Item has already expired.
        }
        $slidingTtl = $this->defaultTtl;
        if (null !== $key) {
            $patternTtl = $this->getKeyPatternTtl($key);
            if (null !== $patternTtl) {
                $slidingTtl = $patternTtl;
            }
        }

        return $this->fromSeconds($slidingTtl);
    }

    /**
     * Gets the provider's configuration as an array.
     *
     * @return array<string, mixed> the current configuration
     */
    public function getConfiguration(): array
    {
        return [
            'default_ttl' => $this->defaultTtl,
            'operation_ttls' => $this->operationTtls,
            'key_pattern_ttls' => $this->keyPatternTtls,
            'sliding_expiration' => $this->slidingExpiration,
            'max_ttl' => $this->maxTtl,
        ];
    }

    /**
     * Validates if a given TTL value is acceptable according to the provider's rules.
     *
     * @param \DateInterval|\DateTimeInterface|int|null $ttl the TTL value to validate
     *
     * @return bool true if the TTL is valid
     */
    public function validateTtl(\DateInterval|\DateTimeInterface|int|null $ttl): bool
    {
        if (null === $ttl) {
            return true;
        }
        try {
            $seconds = $this->toSeconds($ttl);
            if (null !== $seconds) {
                $this->validateTtlSeconds($seconds);
            }

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Processes a TTL value of various types into a standard DateTimeInterface object.
     *
     * @param \DateInterval|\DateTimeInterface|int $ttl the TTL to process
     *
     * @return \DateTimeInterface the resulting expiration timestamp
     */
    private function processTtl(\DateInterval|\DateTimeInterface|int $ttl): \DateTimeInterface
    {
        if ($ttl instanceof \DateTimeInterface) {
            $this->validateExpirationTime($ttl);

            return $ttl;
        }
        if ($ttl instanceof \DateInterval) {
            $expiration = (new \DateTimeImmutable())->add($ttl);
            $this->validateExpirationTime($expiration);

            return $expiration;
        }

        return $this->fromSeconds($ttl);
    }

    /**
     * Validates that a TTL in seconds is within the configured bounds.
     *
     * @param int $seconds the TTL in seconds
     *
     * @return int the validated TTL in seconds
     */
    private function validateTtlSeconds(int $seconds): int
    {
        if ($seconds < ExpirationProvider::MIN_TTL_SECONDS) {
            throw InvalidArgumentException::invalidTtl(sprintf('TTL must be at least %d second(s)', ExpirationProvider::MIN_TTL_SECONDS));
        }
        if (null !== $this->maxTtl && $seconds > $this->maxTtl) {
            throw InvalidArgumentException::invalidTtl(sprintf('TTL cannot exceed %d seconds', $this->maxTtl));
        }

        return $seconds;
    }

    /**
     * Validates that an absolute expiration timestamp is in the future and within the max TTL.
     *
     * @param \DateTimeInterface $expiration the expiration timestamp
     */
    private function validateExpirationTime(\DateTimeInterface $expiration): void
    {
        $now = new \DateTimeImmutable();
        if ($expiration <= $now) {
            throw InvalidArgumentException::invalidTtl('Expiration time must be in the future');
        }
        if (null !== $this->maxTtl) {
            $maxExpiration = $now->modify('+' . $this->maxTtl . ' seconds');
            if ($expiration > $maxExpiration) {
                throw InvalidArgumentException::invalidTtl(sprintf('Expiration time cannot exceed %d seconds from now', $this->maxTtl));
            }
        }
    }

    /**
     * Checks if a key matches a wildcard pattern.
     *
     * @param string $key the key to check
     * @param string $pattern The wildcard pattern (e.g., 'user:*').
     *
     * @return bool true if the key matches the pattern
     */
    private function matchesPattern(string $key, string $pattern): bool
    {
        $regex = '/^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';

        return (bool) preg_match($regex, $key);
    }

    /**
     * Validates the provider's configuration upon instantiation.
     */
    private function validateConfiguration(): void
    {
        if ($this->defaultTtl < ExpirationProvider::MIN_TTL_SECONDS) {
            throw InvalidArgumentException::invalidConfiguration(sprintf('Default TTL must be at least %d second(s)', ExpirationProvider::MIN_TTL_SECONDS));
        }
        if (null !== $this->maxTtl) {
            if ($this->maxTtl < ExpirationProvider::MIN_TTL_SECONDS) {
                throw InvalidArgumentException::invalidConfiguration(sprintf('Max TTL must be at least %d second(s)', ExpirationProvider::MIN_TTL_SECONDS));
            }
            if ($this->defaultTtl > $this->maxTtl) {
                throw InvalidArgumentException::invalidConfiguration('Default TTL cannot exceed max TTL');
            }
            foreach ($this->operationTtls as $operation => $ttl) {
                if ($ttl > $this->maxTtl) {
                    throw InvalidArgumentException::invalidConfiguration(sprintf('Operation TTL for "%s" cannot exceed max TTL', $operation));
                }
            }
            foreach ($this->keyPatternTtls as $pattern => $ttl) {
                if ($ttl > $this->maxTtl) {
                    throw InvalidArgumentException::invalidConfiguration(sprintf('Pattern TTL for "%s" cannot exceed max TTL', $pattern));
                }
            }
        }
    }
}
