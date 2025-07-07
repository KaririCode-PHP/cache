<?php


return [
    'default_ttl' => (int)($_ENV['CACHE_DEFAULT_TTL'] ?? 3600),

    // Multi-layer cache configuration
    'layers' => [
        // Level 1: APCu for ultra-fast local cache
        [
            'driver' => 'apcu',
            'priority' => 5,
            'config' => [
                'key_prefix' => 'app:',
            ],
        ],

        // Level 2: Redis for shared, persistent cache
        [
            'driver' => 'redis',
            'priority' => 10,
            'config' => [
                'host' => '${REDIS_HOST:localhost}',
                'port' => '${REDIS_PORT:6379}',
                'password' => '${REDIS_PASSWORD:}',
                'database' => '${REDIS_DB:0}',
                'persistent' => true,
                'compression' => true,
                'key_prefix' => 'app:',
                'tag_prefix' => 'tags:',
                'topic_prefix' => 'topics:',
            ],
        ],

        // Level 3: Filesystem fallback
        [
            'driver' => 'filesystem',
            'priority' => 90,
            'config' => [
                'directory' => '${CACHE_DIR:/var/cache/app}',
                'max_size' => 500 * 1024 * 1024, // 500MB
                'cleanup_probability' => 0.02, // 2%
            ],
        ],
    ],

    // Serialization strategy
    'serializer' => '${CACHE_SERIALIZER:compressed_igbinary}',

    // Advanced features
    'stampede_protection' => true,
    'metrics' => true,
    'sliding_expiration' => '${CACHE_SLIDING_EXPIRATION:true}',

    // Namespace configuration
    'key_prefix' => '${APP_NAME:app}:',
    'namespace_separator' => ':',
];
