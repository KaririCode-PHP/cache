<?php

declare(strict_types=1);

namespace KaririCode\Cache\Transaction;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheTransactionManager as CacheTransactionManagerContract;
use KaririCode\Cache\Exception\CacheException;

/**
 * Transaction manager for cache operations with rollback capabilities.
 */
final class CacheTransactionManager implements CacheTransactionManagerContract
{
    private bool $inTransaction = false;
    private array $operations = [];
    private array $rollbackData = [];

    public function __construct(private CacheDriver $driver)
    {
    }

    public function begin(): void
    {
        if ($this->inTransaction) {
            throw CacheException::operationFailed('begin', 'transaction', new \RuntimeException('Transaction already in progress'));
        }

        $this->inTransaction = true;
        $this->operations = [];
        $this->rollbackData = [];
    }

    public function commit(): bool
    {
        if (!$this->inTransaction) {
            throw CacheException::operationFailed('commit', 'transaction', new \RuntimeException('No transaction in progress'));
        }

        $success = true;

        // Execute all operations
        foreach ($this->operations as $operation) {
            try {
                $this->executeOperation($operation);
            } catch (\Exception $e) {
                $success = false;
                break;
            }
        }

        if (!$success) {
            // Rollback on failure
            $this->rollback();

            return false;
        }

        $this->inTransaction = false;
        $this->operations = [];
        $this->rollbackData = [];

        return true;
    }

    public function rollback(): bool
    {
        if (!$this->inTransaction) {
            return false;
        }

        // Restore previous values
        foreach ($this->rollbackData as $key => $data) {
            if ($data['existed']) {
                $this->driver->set($key, $data['value'], $data['expiration']);
            } else {
                $this->driver->delete($key);
            }
        }

        $this->inTransaction = false;
        $this->operations = [];
        $this->rollbackData = [];

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function scheduleSet(string $key, mixed $value, ?\DateTimeInterface $expiration = null): void
    {
        if (!$this->inTransaction) {
            throw CacheException::operationFailed('scheduleSet', $key, new \RuntimeException('No transaction in progress'));
        }

        // Store current state for rollback
        $this->storeRollbackData($key);

        $this->operations[] = [
            'type' => 'set',
            'key' => $key,
            'value' => $value,
            'expiration' => $expiration,
        ];
    }

    public function scheduleDelete(string $key): void
    {
        if (!$this->inTransaction) {
            throw CacheException::operationFailed('scheduleDelete', $key, new \RuntimeException('No transaction in progress'));
        }

        // Store current state for rollback
        $this->storeRollbackData($key);

        $this->operations[] = [
            'type' => 'delete',
            'key' => $key,
        ];
    }

    private function executeOperation(array $operation): void
    {
        match ($operation['type']) {
            'set' => $this->driver->set($operation['key'], $operation['value'], $operation['expiration']),
            'delete' => $this->driver->delete($operation['key']),
            default => throw new \InvalidArgumentException("Unknown operation type: {$operation['type']}")
        };
    }

    private function storeRollbackData(string $key): void
    {
        if (!isset($this->rollbackData[$key])) {
            $value = $this->driver->get($key);
            $this->rollbackData[$key] = [
                'existed' => null !== $value,
                'value' => $value,
                'expiration' => null, // We would need to track expiration separately
            ];
        }
    }
}
