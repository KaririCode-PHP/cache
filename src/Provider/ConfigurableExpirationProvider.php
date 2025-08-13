<?php

declare(strict_types=1);

namespace KaririCode\Cache\Provider;

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

    private function __construct(ExpirationProviderConfig $config)
    {
        $this->defaultTtl = $config->defaultTtl;
        $this->operationTtls = $config->operationTtls;
        $this->keyPatternTtls = $config->keyPatternTtls;
        $this->slidingExpiration = $config->slidingExpiration;
        $this->maxTtl = $config->maxTtl;

        $this->validateConfiguration();
    }

    public static function fromConfig(ExpirationProviderConfig $config): self
    {
        return new self($config);
    }

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

    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    public function isExpired(?\DateTimeInterface $expiration): bool
    {
        if (null === $expiration) {
            return false;
        }

        return $expiration <= new \DateTimeImmutable();
    }

    public function toSeconds(mixed $ttl): ?int
    {
        if (!is_int($ttl) && !$ttl instanceof \DateInterval && !$ttl instanceof \DateTimeInterface && null !== $ttl) {
            throw InvalidArgumentException::invalidTtl('TTL must be an integer, DateInterval, DateTimeInterface, or null.');
        }

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
        throw InvalidArgumentException::invalidTtl('Invalid TTL type provided.');
    }

    public function fromSeconds(int $seconds): \DateTimeInterface
    {
        $validatedSeconds = $this->validateTtlSeconds($seconds);

        return new \DateTimeImmutable('+' . $validatedSeconds . ' seconds');
    }

    public function getOperationTtl(string $operation): ?int
    {
        return $this->operationTtls[$operation] ?? null;
    }

    public function getKeyPatternTtl(string $key): ?int
    {
        foreach ($this->keyPatternTtls as $pattern => $ttl) {
            if ($this->matchesPattern($key, $pattern)) {
                return $ttl;
            }
        }

        return null;
    }

    public function isSlidingExpiration(): bool
    {
        return $this->slidingExpiration;
    }

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
            return $currentExpiration;
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

    public function validateTtl(mixed $ttl): bool
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

    private function validateTtlSeconds(int $seconds): int
    {
        $this->ensureTtlIsNotNegative($seconds);
        $this->ensureTtlIsAboveMinimum($seconds);
        $this->ensureTtlIsWithinMaximum($seconds);

        return $seconds;
    }

    private function ensureTtlIsNotNegative(int $seconds): void
    {
        if ($seconds < 0) {
            throw InvalidArgumentException::invalidTtl('TTL in seconds cannot be negative.');
        }
    }

    private function ensureTtlIsAboveMinimum(int $seconds): void
    {
        if ($seconds > 0 && $seconds < ExpirationProvider::MIN_TTL_SECONDS) {
            throw InvalidArgumentException::invalidTtl(sprintf('TTL must be at least %d second(s)', ExpirationProvider::MIN_TTL_SECONDS));
        }
    }

    private function ensureTtlIsWithinMaximum(int $seconds): void
    {
        if (null !== $this->maxTtl && $seconds > $this->maxTtl) {
            throw InvalidArgumentException::invalidTtl(sprintf('TTL cannot exceed %d seconds', $this->maxTtl));
        }
    }

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

    private function matchesPattern(string $key, string $pattern): bool
    {
        return fnmatch($pattern, $key);
    }

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
