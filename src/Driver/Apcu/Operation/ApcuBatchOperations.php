<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMonitoringService;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;

/**
 * Handles batch operations for APCu.
 * SRP: This class is only responsible for multi-key operations.
 */
final class ApcuBatchOperations
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

    /**
     * @param array<string> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys): array
    {
        $this->validateKeys($keys);

        if (empty($keys)) {
            return [];
        }

        return (array) $this->errorHandler->executeWithRetry(
            fn () => $this->performGetMultiple($keys),
            'getMultiple',
            implode(',', $keys)
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        if (empty($values)) {
            return true;
        }

        $this->validateMultipleValues($values);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSetMultiple($values, $expiration),
            'setMultiple',
            implode(',', array_keys($values))
        );
    }

    /**
     * @param array<string> $keys
     */
    public function deleteMultiple(array $keys): bool
    {
        $this->validateKeys($keys);

        if (empty($keys)) {
            return true;
        }

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performDeleteMultiple($keys),
            'deleteMultiple',
            implode(',', $keys)
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
        $prefixedValues = [];
        $ttl = $this->calculateTtlInSeconds($expiration);

        foreach ($values as $key => $value) {
            $prefixedValues[$this->config->keyPrefix . $key] = $this->serialize($value);
        }

        $failures = apcu_store($prefixedValues, null, $ttl);

        if (!is_array($failures)) {
            // A non-array return indicates a catastrophic failure.
            $this->monitoringService->recordError();

            return false;
        }

        $successCount = count($prefixedValues) - count($failures);

        for ($i = 0; $i < $successCount; ++$i) {
            $this->monitoringService->recordWrite();
        }
        for ($i = 0; $i < count($failures); ++$i) {
            $this->monitoringService->recordError();
        }

        return empty($failures);
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

        // ANÁLISE: apcu_delete() com um array de chaves sempre retorna um array com as chaves que falharam.
        $failures = apcu_delete($prefixedKeys);

        // Se o retorno não for um array, algo muito errado aconteceu (falha catastrófica).
        if (!is_array($failures)) {
            $this->monitoringService->recordError();

            return false;
        }

        $deletedCount = count($prefixedKeys) - count($failures);
        for ($i = 0; $i < $deletedCount; ++$i) {
            $this->monitoringService->recordDelete();
        }

        // A operação é considerada um sucesso se o array de falhas estiver vazio.
        return empty($failures);
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
