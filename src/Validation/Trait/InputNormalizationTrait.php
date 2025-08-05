<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Fornece métodos reutilizáveis para normalizar e validar entradas,
 * como listas de chaves de cache.
 */
trait InputNormalizationTrait
{
    // Garante que o método de validação de chave individual esteja disponível.
    use CacheKeyValidationTrait;

    /**
     * Valida um iterável de chaves, garantindo que todos os itens são strings válidas,
     * e retorna um array normalizado sem chaves duplicadas.
     *
     * @param iterable<string> $keys as chaves a serem validadas e normalizadas
     *
     * @throws InvalidArgumentException se alguma chave no iterável for inválida
     *
     * @return array<string> um array de chaves únicas e válidas
     */
    protected function validateAndNormalizeKeys(iterable $keys): array
    {
        $normalizedKeys = [];
        $keysArray = is_array($keys) ? $keys : iterator_to_array($keys);

        foreach ($keysArray as $key) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('All keys must be strings');
            }

            // Utiliza o método robusto do CacheKeyValidationTrait
            $this->validateKey($key);
            $normalizedKeys[] = $key;
        }

        return array_unique($normalizedKeys);
    }
}
