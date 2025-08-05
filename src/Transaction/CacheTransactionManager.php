<?php

declare(strict_types=1);

namespace KaririCode\Cache\Transaction;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheTransactionManager as CacheTransactionManagerContract;
use KaririCode\Cache\Contract\Transaction\Operation;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Transaction\DTO\TransactionState;
use KaririCode\Cache\Transaction\Operation\DeleteOperation;
use KaririCode\Cache\Transaction\Operation\SetOperation;
use KaririCode\Contract\ImmutableValue;

final class CacheTransactionManager implements CacheTransactionManagerContract
{
    private bool $inTransaction = false;

    /** @var array<int, Operation> */
    private array $operations = [];

    /** @var array<string, array{existed: bool, value: mixed, expiration: \DateTimeInterface|null}> */
    private array $rollbackData = [];

    /** @var array<string, \DateTimeImmutable> */
    private array $savepoints = [];

    private \DateTimeImmutable $transactionStartTime;

    public function __construct(private readonly CacheDriver $driver)
    {
        $this->transactionStartTime = new \DateTimeImmutable();
    }

    public function begin(array $options = []): void
    {
        if ($this->inTransaction) {
            throw CacheException::operationFailed('begin', 'transaction', new \RuntimeException('Transaction already in progress'));
        }
        $this->clearTransactionState();
        $this->inTransaction = true;
        $this->transactionStartTime = new \DateTimeImmutable();
    }

    public function commit(): bool
    {
        if (!$this->inTransaction) {
            throw CacheException::operationFailed('commit', 'transaction', new \RuntimeException('No transaction in progress'));
        }

        try {
            foreach ($this->operations as $operation) {
                $this->executeOperation($operation);
            }
        } catch (\Throwable $e) {
            $this->rollback();

            return false;
        }

        $this->clearTransactionState();

        return true;
    }

    public function rollback(): bool
    {
        if (!$this->inTransaction) {
            return false;
        }

        try {
            foreach (array_reverse($this->rollbackData, true) as $key => $data) {
                $existed = $data['existed'];
                $value = $data['value'];

                if ($existed) {
                    $this->driver->set($key, $value);
                } else {
                    $this->driver->delete($key);
                }
            }
        } catch (\Throwable $e) {
            error_log('Rollback operation failed: ' . $e->getMessage());
        }

        $this->clearTransactionState();

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function savepoint(string $name): void
    {
        if (!$this->inTransaction) {
            throw CacheException::operationFailed('savepoint', $name, new \RuntimeException('No transaction in progress'));
        }
        $this->savepoints[$name] = new \DateTimeImmutable();
    }

    public function rollbackToSavepoint(string $name): bool
    {
        if (!$this->inTransaction) {
            throw CacheException::operationFailed('rollbackToSavepoint', $name, new \RuntimeException('No transaction in progress'));
        }

        if (!isset($this->savepoints[$name])) {
            throw CacheException::operationFailed('rollbackToSavepoint', $name, new \RuntimeException(sprintf('Savepoint "%s" does not exist', $name)));
        }

        $savepointTime = $this->savepoints[$name];

        $this->operations = array_filter(
            $this->operations,
            fn (Operation $operation) => $operation->getTimestamp() <= $savepointTime
        );

        $this->savepoints = array_filter(
            $this->savepoints,
            fn (\DateTimeImmutable $time) => $time <= $savepointTime
        );

        return true;
    }

    public function getTransactionState(): ImmutableValue
    {
        return new TransactionState(
            $this->inTransaction,
            $this->savepoints,
            $this->operations,
            $this->transactionStartTime
        );
    }

    public function scheduleSet(string $key, mixed $value): void
    {
        if (!$this->inTransaction) {
            throw CacheException::operationFailed('scheduleSet', $key, new \RuntimeException('No transaction in progress'));
        }
        $this->storeRollbackData($key);
        $this->operations[] = new SetOperation($key, $value);
    }

    public function scheduleDelete(string $key): void
    {
        if (!$this->inTransaction) {
            throw CacheException::operationFailed('scheduleDelete', $key, new \RuntimeException('No transaction in progress'));
        }
        $this->storeRollbackData($key);
        $this->operations[] = new DeleteOperation($key);
    }

    /**
     * Execute a single operation.
     *
     * @param Operation $operation Operation to execute
     */
    private function executeOperation(Operation $operation): void
    {
        match (true) {
            $operation instanceof SetOperation => $this->executeSetOperation($operation),
            $operation instanceof DeleteOperation => $this->executeDeleteOperation($operation),
            default => throw InvalidArgumentException::invalidValue('Unknown operation type')
        };
    }

    /**
     * Execute a set operation.
     *
     * @param SetOperation $operation Operation to execute
     */
    private function executeSetOperation(Operation $operation): void
    {
        $this->driver->set($operation->key, $operation->value);
    }

    /**
     * Execute a delete operation.
     *
     * @param DeleteOperation $operation Operation to execute
     */
    private function executeDeleteOperation(Operation $operation): void
    {
        $this->driver->delete($operation->key);
    }

    private function storeRollbackData(string $key): void
    {
        if (!isset($this->rollbackData[$key])) {
            $value = $this->driver->get($key);
            $this->rollbackData[$key] = [
                'existed' => null !== $value,
                'value' => $value,
                'expiration' => null,
            ];
        }
    }

    private function clearTransactionState(): void
    {
        $this->inTransaction = false;
        $this->operations = [];
        $this->rollbackData = [];
        $this->savepoints = [];
    }
}
