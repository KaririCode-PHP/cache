<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Filesystem;

interface DirectoryManager
{
    public function ensureExists(string $directory): bool;

    public function clear(): bool;

    public function getSize(): int;

    public function getFileCount(): int;

    public function isWritable(string $directory): bool;

    public function createSubdirectoryPath(string $key): string;

    public function getCacheFiles(): array;
}
