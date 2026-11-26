<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Component;

use KaririCode\Contract\Logging\Logger;

/**
 * Realiza operações de manutenção no cache APCu.
 * A sua única responsabilidade é limpar entradas expiradas e otimizar.
 */
final class ApcuMaintenanceService
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function perform(array $options = []): bool
    {
        $startTime = microtime(true);
        $this->logger->info('Starting APCu cache maintenance');

        try {
            $operations = $options['operations'] ?? ['cleanup', 'optimize'];
            $success = true;

            if (in_array('cleanup', $operations, true)) {
                $success = $this->performCleanup();
            }

            if ($success && in_array('optimize', $operations, true)) {
                $this->performOptimization();
            }

            $duration = microtime(true) - $startTime;
            $this->logger->info('APCu cache maintenance completed', [
                'duration_ms' => round($duration * 1000, 2),
                'success' => $success,
            ]);

            return $success;
        } catch (\Throwable $e) {
            $this->logger->error('APCu cache maintenance failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function performCleanup(): bool
    {
        $expiredCount = 0;
        $iterator = new \APCUIterator();

        foreach ($iterator as $item) {
            // 1. Adicionamos esta cláusula de guarda para garantir o tipo.
            if (!is_array($item)) {
                continue;
            }

            // 2. Agora a ferramenta sabe que $item é um array.
            if ($this->isExpired($item)) {
                apcu_delete((string) $item['key']);
                ++$expiredCount;
            }
        }

        if ($expiredCount > 0) {
            $this->logger->info('APCu cleanup completed', ['expired_entries_removed' => $expiredCount]);
        }

        return true;
    }

    private function performOptimization(): void
    {
        $info = apcu_cache_info(true);
        if (is_array($info)) {
            $this->logger->info('APCu memory analysis', [
                'memory_usage' => $info['mem_size'] ?? 0,
                'num_entries' => $info['num_entries'] ?? 0,
                'num_hits' => $info['num_hits'] ?? 0,
                'num_misses' => $info['num_misses'] ?? 0,
            ]);
        }
    }

    private function isExpired(mixed $item): bool
    {
        if (!is_array($item) || !isset($item['ttl'], $item['creation_time'])) {
            return false;
        }

        return (int) $item['ttl'] > 0
               && ((int) $item['creation_time'] + (int) $item['ttl']) < time();
    }
}
