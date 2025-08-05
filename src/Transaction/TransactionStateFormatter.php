<?php

declare(strict_types=1);

namespace KaririCode\Cache\Transaction;

use KaririCode\Cache\Contract\Transaction\Operation;
use KaririCode\Cache\Transaction\DTO\TransactionState;
use KaririCode\Cache\Transaction\Operation\DeleteOperation;
use KaririCode\Cache\Transaction\Operation\SetOperation;

/**
 * Formata um objeto TransactionState em um array para logging ou debugging.
 */
final class TransactionStateFormatter
{
    /**
     * Converte o estado da transação em um array legível.
     *
     * @return array<string, mixed>
     */
    public function format(TransactionState $state): array
    {
        return [
            'active' => $state->isActive(),
            'start_time' => $state->getStartTime()->format(\DateTimeInterface::ATOM),
            'duration' => $state->getDuration(),
            'operation_count' => $state->getOperationCount(),
            'savepoint_count' => $state->getSavepointCount(),
            'savepoint_names' => $state->getSavepointNames(),
            'operations' => array_map(
                [$this, 'formatOperation'],
                $state->getOperations()
            ),
        ];
    }

    /**
     * Formata um único objeto Operation em um array.
     *
     * @return array<string, mixed>
     */
    private function formatOperation(Operation $operation): array
    {
        if ($operation instanceof SetOperation) {
            return [
                'type' => 'set',
                'key' => $operation->getKey(),
                'value_type' => gettype($operation->value),
                'timestamp' => $operation->getTimestamp()->format(\DateTimeInterface::ATOM),
            ];
        }

        if ($operation instanceof DeleteOperation) {
            return [
                'type' => 'delete',
                'key' => $operation->getKey(),
                'timestamp' => $operation->getTimestamp()->format(\DateTimeInterface::ATOM),
            ];
        }

        // Fallback para tipos de operação desconhecidos
        return [
            'type' => 'unknown',
            'key' => $operation->getKey(),
            'class' => get_class($operation),
        ];
    }
}
