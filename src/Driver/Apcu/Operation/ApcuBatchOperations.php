<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMonitoringService;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;

/**
 * Handles batch operations for APCu (multi-key operations).
 * SRP: This class is only responsible for multi-key operations.
 * Uses ExpirationProvider for TTL management - follows Strategy Pattern.
 */
final class ApcuBatchOperations
{
    use SerializationTrait;
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;

    public function __construct(
        private ApcuConfig $config,
        private ApcuErrorHandler $errorHandler,
        private ApcuMonitoringService $monitoringService,
        private ExpirationProvider $expirationProvider
    ) {
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys): iterable
    {
        $keyArray = $this->normalizeToArray($keys);
        $this->validateKeys($keyArray);

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performGetMultiple($keyArray),
            'getMultiple',
            implode(',', $keyArray)
        );
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, ?\DateTimeInterface $expiration = null): bool
    {
        $valueArray = $this->normalizeToArray($values);
        $this->validateMultipleValues($valueArray);

        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForOperation('setMultiple')
        );

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSetMultiple($valueArray, $finalExpiration),
            'setMultiple'
        );
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $keyArray = $this->normalizeToArray($keys);
        $this->validateKeys($keyArray);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performDeleteMultiple($keyArray),
            'deleteMultiple',
            implode(',', $keyArray)
        );
    }

    /**
     * @param array<string> $keys
     *
     * @return array<string, mixed>
     */
    private function performGetMultiple(array $keys): array
    {
        $prefixedKeys = array_map(
            fn ($key) => $this->config->keyPrefix . $key,
            $keys
        );

        $rawValues = apcu_fetch($prefixedKeys, $success);

        if (!is_array($rawValues)) {
            return array_fill_keys($keys, null);
        }

        $results = [];
        foreach ($keys as $key) {
            $prefixedKey = $this->config->keyPrefix . $key;

            if (isset($rawValues[$prefixedKey])) {
                $results[$key] = $this->unserialize($rawValues[$prefixedKey]);
                $this->monitoringService->recordHit();
            } else {
                $results[$key] = null;
                $this->monitoringService->recordMiss();
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function performSetMultiple(array $values, ?\DateTimeInterface $expiration): bool
    {
        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;

        $success = true;
        foreach ($values as $key => $value) {
            $fullKey = $this->config->keyPrefix . (string) $key;
            $serialized = $this->serialize($value);

            if (!apcu_store($fullKey, $serialized, $ttl)) {
                $success = false;
                $this->monitoringService->recordError();
            } else {
                $this->monitoringService->recordWrite();
            }
        }

        return $success;
    }

    /**
     * @param array<string> $keys
     */
    private function performDeleteMultiple(array $keys): bool
    {
        $prefixedKeys = array_map(
            fn ($key) => $this->config->keyPrefix . $key,
            $keys
        );

        $failures = apcu_delete($prefixedKeys);

        if (!is_array($failures)) {
            $this->monitoringService->recordError();

            return false;
        }

        $deletedCount = count($prefixedKeys) - count($failures);
        for ($i = 0; $i < $deletedCount; ++$i) {
            $this->monitoringService->recordDelete();
        }

        return empty($failures);
    }

    /**
     * @param iterable<mixed> $iterable
     *
     * @return array<mixed>
     */
    private function normalizeToArray(iterable $iterable): array
    {
        if (is_array($iterable)) {
            return $iterable;
        }

        return iterator_to_array($iterable);
    }

    /**
     * @param array<string> $keys
     */
    private function validateKeys(array $keys): void
    {
        foreach ($keys as $key) {
            $this->validateKey($key);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function validateMultipleValues(array $values): void
    {
        foreach ($values as $key => $value) {
            try {
                $this->validateKey((string) $key);
                $this->validateValue($value);
            } catch (\Throwable $e) {
                if ($e instanceof InvalidArgumentException) {
                    throw $e;
                }
                throw InvalidArgumentException::invalidValue("Invalid value for key '{$key}': " . $e->getMessage(), $e);
            }
        }
    }
}
