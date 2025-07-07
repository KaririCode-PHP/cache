<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface CacheHealthCheck
{
    public function isHealthy(): bool;

    public function getHealthStatus(): array;

    public function getLastError(): ?string;
}
