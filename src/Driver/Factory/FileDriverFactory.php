<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Driver\FilesystemDriver;

/**
 * Filesystem driver factory with directory validation.
 */
final class FilesystemDriverFactory implements CacheDriverFactory
{
    public function create(array $config): CacheDriver
    {
        $directory = $config['directory'] ?? sys_get_temp_dir() . '/kariricode-cache';
        $keyPrefix = $config['key_prefix'] ?? 'cache_';
        $maxSize = $config['max_size'] ?? 100 * 1024 * 1024; // 100MB
        $cleanupProbability = $config['cleanup_probability'] ?? 0.01; // 1%

        return new FilesystemDriver($directory, $keyPrefix, $maxSize, $cleanupProbability);
    }
}
