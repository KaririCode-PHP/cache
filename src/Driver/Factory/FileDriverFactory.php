<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Factory;

use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheDriverFactory;
use KaririCode\Cache\Driver\FileDriver;

class FileDriverFactory implements CacheDriverFactory
{
    public function createDriver(array $options, DriverManager $driverManager): CacheDriver
    {
        $directory = $options['directory'] ?? sys_get_temp_dir() . '/cache';

        return new FileDriver($directory, $transactionManager);
    }
}
