<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Component;

use KaririCode\Cache\Contract\DriverStats;

/**
 * Serviço de monitorização para o driver APCu.
 * A sua única responsabilidade é registar métricas de utilização (cliente)
 * e fornecer estatísticas do estado do servidor APCu (servidor).
 */
final class ApcuMonitoringService implements DriverStats
{
    private int $hits = 0;
    private int $misses = 0;
    private int $writes = 0;
    private int $deletes = 0;
    private int $errors = 0;

    public function __construct(private readonly bool $enableStatistics = true)
    {
    }

    /**
     * Regista um acerto no cache (hit).
     */
    public function recordHit(): void
    {
        if ($this->enableStatistics) {
            ++$this->hits;
        }
    }

    /**
     * Regista uma falha no cache (miss).
     */
    public function recordMiss(): void
    {
        if ($this->enableStatistics) {
            ++$this->misses;
        }
    }

    /**
     * Regista uma operação de escrita no cache.
     */
    public function recordWrite(): void
    {
        if ($this->enableStatistics) {
            ++$this->writes;
        }
    }

    /**
     * Regista uma operação de remoção do cache.
     */
    public function recordDelete(): void
    {
        if ($this->enableStatistics) {
            ++$this->deletes;
        }
    }

    /**
     * Regista um erro numa operação de cache.
     */
    public function recordError(): void
    {
        if ($this->enableStatistics) {
            ++$this->errors;
        }
    }

    /**
     * Retorna o nome do driver.
     */
    public function getDriverName(): string
    {
        return 'APCu';
    }

    /**
     * Retorna a versão da extensão APCu.
     */
    public function getDriverVersion(): ?string
    {
        return phpversion('apcu') ?: null;
    }

    /**
     * Retorna a quantidade de memória utilizada pelo APCu.
     */
    public function getMemoryUsage(): ?int
    {
        $info = apcu_cache_info(true);
        if (!is_array($info) || !isset($info['mem_size'])) {
            return null;
        }

        return is_numeric($info['mem_size']) ? (int) $info['mem_size'] : null;
    }

    /**
     * Retorna o número total de itens no cache.
     */
    public function getItemCount(): ?int
    {
        $info = apcu_cache_info(true);
        if (!is_array($info) || !isset($info['num_entries'])) {
            return null;
        }

        return is_numeric($info['num_entries']) ? (int) $info['num_entries'] : null;
    }

    /**
     * Retorna um array com todas as estatísticas brutas do APCu e do cliente.
     *
     * @return array<string, mixed>
     */
    public function getRawStats(): array
    {
        $cacheInfo = apcu_cache_info(true) ?: [];
        $smaInfo = apcu_sma_info(true) ?: [];

        return [
            'cache_info' => $cacheInfo,
            'memory_info' => $smaInfo,
            'client_stats' => [
                'hits' => $this->hits,
                'misses' => $this->misses,
                'writes' => $this->writes,
                'deletes' => $this->deletes,
                'errors' => $this->errors,
            ],
        ];
    }
}
