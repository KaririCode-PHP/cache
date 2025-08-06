<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMonitoringService;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Service\ValidationService;

/**
 * Handles batch operations (get/set/delete multiple) for the ApcuDriver.
 *
 * This class is solely responsible for multi-key operations, adhering to SRP.
 * It internally uses the ExpirationProvider to calculate the appropriate TTL
 * for batch-set operations, following the Strategy Pattern.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ApcuBatchOperations
{
    use SerializationTrait;

    private readonly ValidationService $validator;

    /**
     * @param ApcuConfig $config configuration settings for APCu
     * @param ApcuErrorHandler $errorHandler error handling and retry logic
     * @param ApcuMonitoringService $monitoringService performance monitoring
     * @param ExpirationProvider $expirationProvider strategy for TTL management
     */
    public function __construct(
        private readonly ApcuConfig $config,
        private readonly ApcuErrorHandler $errorHandler,
        private readonly ApcuMonitoringService $monitoringService,
        private readonly ExpirationProvider $expirationProvider
    ) {
        $this->validator = new ValidationService();
    }

    /**
     * Retrieves multiple items from the cache.
     *
     * @param iterable<string> $keys a list of keys to retrieve
     *
     * @return iterable<string, mixed> a map of keys to their values
     */
    public function getMultiple(iterable $keys): iterable
    {
        $keyArray = $this->normalizeToArray($keys);
        $this->validator->validateKeys($keyArray);

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performGetMultiple($keyArray),
            'getMultiple',
            implode(',', $keyArray)
        );
    }

    /**
     * Stores multiple items in the cache, calculating the TTL internally.
     *
     * @param iterable<string, mixed> $values a map of keys to their values
     *
     * @return bool true on success
     */
    public function setMultiple(iterable $values): bool
    {
        $valueArray = $this->normalizeToArray($values);
        $this->validator->validateMultipleValues($valueArray);

        // Calculate expiration using the provider's strategy for batch operations.
        $finalExpiration = $this->expirationProvider->calculateExpiration(null, null, 'setMultiple');

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSetMultiple($valueArray, $finalExpiration),
            'setMultiple'
        );
    }

    /**
     * Deletes multiple items from the cache.
     *
     * @param iterable<string> $keys a list of keys to delete
     *
     * @return bool true on success
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $keyArray = $this->normalizeToArray($keys);
        $this->validator->validateKeys($keyArray);

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

        $rawValues = apcu_fetch($prefixedKeys);

        if (!is_array($rawValues)) {
            // On failure, apcu_fetch can return false. Treat as a miss for all keys.
            foreach ($keys as $key) {
                $this->monitoringService->recordMiss();
            }

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

        $prefixedValues = [];
        foreach ($values as $key => $value) {
            $prefixedValues[$this->config->keyPrefix . (string) $key] = $this->serialize($value);
        }

        // apcu_store returns an array of failed keys on failure.
        $failedKeys = apcu_store($prefixedValues, null, $ttl);

        $success = empty($failedKeys);

        // Record metrics based on success/failure.
        $totalItems = count($values);
        $failedCount = count($failedKeys);
        $successCount = $totalItems - $failedCount;

        for ($i = 0; $i < $successCount; ++$i) {
            $this->monitoringService->recordWrite();
        }
        for ($i = 0; $i < $failedCount; ++$i) {
            $this->monitoringService->recordError();
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

        $success = empty($failures);

        $deletedCount = count($prefixedKeys) - count($failures);
        for ($i = 0; $i < $deletedCount; ++$i) {
            $this->monitoringService->recordDelete();
        }

        return $success;
    }

    /**
     * @param iterable<mixed> $iterable
     *
     * @return array<mixed>
     */
    private function normalizeToArray(iterable $iterable): array
    {
        return is_array($iterable) ? $iterable : iterator_to_array($iterable);
    }
}
