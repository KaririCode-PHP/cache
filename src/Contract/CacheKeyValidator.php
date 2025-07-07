<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface CacheKeyValidator
{
    public function validateKey(string $key): bool;

    public function sanitizeKey(string $key): string;

    public function getValidationErrors(string $key): array;
}
