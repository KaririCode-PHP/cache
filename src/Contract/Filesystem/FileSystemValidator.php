<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Filesystem;

interface FileSystemValidator
{
    public function validateKey(string $key): void;

    public function validateValue(mixed $value): void;

    public function validatePath(string $path): void;

    public function sanitizeKey(string $key): string;
}
