<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMonitoringService;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;

/**
 * Handles basic CRUD operations for APCu.
 * SRP: This class is only responsible for get, set, has, delete, and clear operations.
 */
final class ApcuCrudOperations
{
    use SerializationTrait;
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use ExpirationManagementTrait;

    public function __construct(
        private ApcuConfig $config,
        private ApcuErrorHandler $errorHandler,
        private ApcuMonitoringService $monitoringService
    ) {
    }

    public function get(string $key): mixed
    {
        $this->validateKey($key);

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performGet($key),
            'get',
            $key
        );
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSet($key, $value, $expiration),
            'set',
            $key
        );
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => apcu_exists($this->config->keyPrefix . $key),
            'has',
            $key
        );
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performDelete($key),
            'delete',
            $key
        );
    }

    public function clear(): bool
    {
        return (bool) $this->errorHandler->executeWithRetry(
            fn () => apcu_clear_cache(),
            'clear'
        );
    }

    private function performGet(string $key): mixed
    {
        $fullKey = $this->config->keyPrefix . $key;
        $value = apcu_fetch($fullKey, $success);

        if ($success) {
            $this->monitoringService->recordHit();

            return $this->unserialize($value);
        }

        $this->monitoringService->recordMiss();

        return null;
    }

    private function performSet(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $serialized = $this->serialize($value);
        $ttl = $this->calculateTtlInSeconds($expiration);

        $result = apcu_store($fullKey, $serialized, $ttl);

        if ($result) {
            $this->monitoringService->recordWrite();
        } else {
            $this->monitoringService->recordError();
        }

        return $result;
    }

    private function performDelete(string $key): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $result = apcu_delete($fullKey);

        if ($result) {
            $this->monitoringService->recordDelete();
        }

        return $result;
    }
}
