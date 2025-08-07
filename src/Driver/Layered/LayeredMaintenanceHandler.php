<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Layered;

use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Driver\LayeredDriver;

/**
 * Handles maintenance operations for a layered cache.
 *
 * This class orchestrates maintenance tasks across all cache layers that
 * support this capability. It relies on the main LayeredDriver to manage the
 * health and availability of each layer via the circuit breaker pattern.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class LayeredMaintenanceHandler implements Maintainable
{
    /**
     * @param array<int, Maintainable> $layers the layers that support maintenance operations
     * @param LayeredDriver $orchestrator the main driver that manages layer state
     */
    public function __construct(
        private readonly array $layers,
        private readonly LayeredDriver $orchestrator
    ) {
    }

    /**
     * Performs maintenance tasks on all available and capable layers.
     *
     * @param array<string, mixed> $options driver-specific options for the maintenance task
     *
     * @return bool true if the operation was successful on all layers, false otherwise
     */
    public function maintenance(array $options = []): bool
    {
        $overallSuccess = true;
        foreach (array_keys($this->layers) as $priority) {
            if (!$this->orchestrator->isLayerAvailable($priority)) {
                continue;
            }

            try {
                if (false === $this->layers[$priority]->maintenance($options)) {
                    $overallSuccess = false;
                } else {
                    $this->orchestrator->recordSuccess($priority);
                }
            } catch (\Throwable $e) {
                $this->orchestrator->handleFailure($priority, $e, 'maintenance');
                $overallSuccess = false;
            }
        }

        return $overallSuccess;
    }
}
