<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Filesystem;

interface FileSystemStatistics
{
    public function getStats(): array;

    public function healthCheck(): array;

    public function recordOperation(string $operation, float $duration): void;
}
