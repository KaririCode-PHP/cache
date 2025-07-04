<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface CacheConfig
{
    public function getDrivers(): array;

    public function getDefaultDriver(): string;

    public function getDriverConfig(string $driver): array;

    public function getDefaultTtl(): int;
}
