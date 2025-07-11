<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Filesystem;

interface FileManager
{
    public function readSecurely(string $filename): ?string;

    public function writeSecurely(string $filename, string $data): bool;

    public function deleteFile(string $filename): bool;

    public function exists(string $filename): bool;

    public function getSize(string $filename): int;

    public function getModificationTime(string $filename): int;
}
