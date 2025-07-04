<?php

return [
    'default_driver' => 'redis',
    'default_ttl' => 3600,
    'drivers' => [
        'file' => [
            'enabled' => 'env:KARIRI_FILE_DRIVER_ENABLED',
            'directory' => 'env:KARIRI_CACHE_FILE_DIRECTORY',
        ],
        'redis' => [
            'enabled' => 'env:KARIRI_REDIS_DRIVER_ENABLED',
            'host' => 'env:KARIRI_REDIS_HOST',
            'port' => 'env:KARIRI_REDIS_PORT',
            'timeout' => 'env:KARIRI_REDIS_TIMEOUT',
            'retry_interval' => 'env:KARIRI_REDIS_RETRY_INTERVAL',
            'read_timeout' => 'env:KARIRI_REDIS_READ_TIMEOUT',
            'password' => 'env:KARIRI_REDIS_PASSWORD',
            'database' => 'env:KARIRI_REDIS_DATABASE',
            'prefix' => 'env:KARIRI_REDIS_PREFIX',
            'compression' => 'env:KARIRI_REDIS_COMPRESSION',
            'compression_level' => 'env:KARIRI_REDIS_COMPRESSION_LEVEL',
        ],
        'memcached' => [
            'enabled' => 'env:KARIRI_MEMCACHED_DRIVER_ENABLED',
            'servers' => [
                [
                    'host' => 'env:KARIRI_MEMCACHED_HOST',
                    'port' => 'env:KARIRI_MEMCACHED_PORT',
                    'weight' => 'env:KARIRI_MEMCACHED_WEIGHT',
                ],
            ],
            'persistent_id' => 'env:KARIRI_MEMCACHED_PERSISTENT_ID',
            'options' => [
                \Memcached::OPT_COMPRESSION => 'env:KARIRI_MEMCACHED_COMPRESSION',
                \Memcached::OPT_BINARY_PROTOCOL => 'env:KARIRI_MEMCACHED_BINARY_PROTOCOL',
                \Memcached::OPT_TCP_NODELAY => 'env:KARIRI_MEMCACHED_TCP_NODELAY',
                \Memcached::OPT_LIBKETAMA_COMPATIBLE => 'env:KARIRI_MEMCACHED_LIBKETAMA_COMPATIBLE',
            ],
            'username' => 'env:KARIRI_MEMCACHED_USERNAME',
            'password' => 'env:KARIRI_MEMCACHED_PASSWORD',
        ],
    ],
];
