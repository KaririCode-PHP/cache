<?php

declare(strict_types=1);

use function KaririCode\Dotenv\env;

/**
 * KaririCode Cache Configuration.
 *
 * A fully‑fledged, layered cache definition embracing:
 *   • Hot‑key promotion (read‑through)
 *   • Per‑layer circuit‑breaker
 *   • Multi‑store fallbacks (Redis, Memcached, APCu, Filesystem, Array)
 *
 * The file is environment‑driven; override any value via your `.env`.
 */
return [
    // ────────────────────────────────────────────────────────────────────────
    // Default Cache Store
    // ────────────────────────────────────────────────────────────────────────
    'default' => env('CACHE_DEFAULT_STORE', 'layered'),

    // ────────────────────────────────────────────────────────────────────────
    // Cache Stores
    // ────────────────────────────────────────────────────────────────────────
    'stores' => [
        // ------------------------------------------------------------------
        // LAYERED: 3‑tier hierarchy (APCu ➜ Redis ➜ Filesystem)
        // ------------------------------------------------------------------
        'layered' => [
            'driver' => 'layered',
            'config' => [
                // ––– Hot‑key promotion ––––––––––––––––––––––––––––––––––––
                'promotion_enabled'   => env('CACHE_PROMOTION_ENABLED', true),
                'promotion_threshold' => (int) env('CACHE_PROMOTION_THRESHOLD', 3),

                // ––– Circuit‑breaker ––––––––––––––––––––––––––––––––––––––
                'circuit_breaker_enabled' => env('CACHE_CIRCUIT_BREAKER_ENABLED', true),
                'max_failures'            => (int) env('CACHE_CIRCUIT_MAX_FAILURES', 5),
                'circuit_timeout'         => (int) env('CACHE_CIRCUIT_TIMEOUT', 60),

                // ––– Layer definitions (fast ➜ durable) –––––––––––––––––––
                'layers' => [
                    [
                        'driver'   => 'apcu',
                        'priority' => 1,
                        'config'   => [
                            'key_prefix'          => env('CACHE_APCU_PREFIX', 'l1:'),
                            'enable_statistics'   => env('APCU_ENABLE_STATISTICS', true),
                            'cleanup_probability' => (float) env('APCU_CLEANUP_PROBABILITY', 0.01),
                        ],
                    ],
                    [
                        'driver'   => 'redis',
                        'priority' => 10,
                        'config'   => [
                            'host'         => env('REDIS_HOST', 'localhost'),
                            'port'         => (int) env('REDIS_PORT', 6379),
                            'password'     => env('REDIS_PASSWORD'),
                            'database'     => (int) env('REDIS_CACHE_DB', 1),
                            'key_prefix'   => env('CACHE_REDIS_PREFIX', 'l2:'),
                            'persistent'   => env('REDIS_PERSISTENT', true),
                            'persistent_id' => env('REDIS_PERSISTENT_ID', 'layered_cache'),
                            'timeout'      => (float) env('REDIS_TIMEOUT', 2.0),
                            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 2.0),
                        ],
                    ],
                    [
                        'driver'   => 'filesystem',
                        'priority' => 100, // deepest tier
                        'config'   => [
                            'directory'         => env('CACHE_FILESYSTEM_DIR', storage_path('cache/layered')),
                            'key_prefix'        => env('CACHE_FILESYSTEM_PREFIX', 'l3_'),
                            'max_size'          => (int) env('CACHE_FILESYSTEM_MAX_SIZE', 104857600),
                            'cleanup_probability' => (float) env('CACHE_CLEANUP_PROBABILITY', 0.01),
                            'use_locking'       => env('FILESYSTEM_FILE_LOCKING', true),
                            'use_compression'   => env('FILESYSTEM_COMPRESSION', true),
                            'directory_levels'  => (int) env('FILESYSTEM_DIR_LEVELS', 2),
                        ],
                    ],
                ],
            ],
        ],

        // ------------------------------------------------------------------
        // TWO_TIER: Redis + Memcached (no APCu, useful in container swarms)
        // ------------------------------------------------------------------
        'two_tier' => [
            'driver' => 'layered',
            'config' => [
                'promotion_enabled'   => env('CACHE_PROMOTION_ENABLED', true),
                'promotion_threshold' => (int) env('CACHE_PROMOTION_THRESHOLD', 5),
                'layers' => [
                    [
                        'driver'   => 'redis',
                        'priority' => 1,
                        'config'   => [
                            'host'       => env('REDIS_HOST', 'localhost'),
                            'port'       => (int) env('REDIS_PORT', 6379),
                            'database'   => 2,
                            'key_prefix' => 'fast:',
                        ],
                    ],
                    [
                        'driver'   => 'memcached',
                        'priority' => 10,
                        'config'   => [
                            'servers' => [[
                                'host'   => env('MEMCACHED_HOST', '127.0.0.1'),
                                'port'   => (int) env('MEMCACHED_PORT', 11211),
                                'weight' => 100,
                            ]],
                            'key_prefix' => 'backup:',
                        ],
                    ],
                ],
            ],
        ],

        // ------------------------------------------------------------------
        // Stand‑alone drivers (for direct access or health checks)
        // ------------------------------------------------------------------
        'redis' => [
            'driver' => 'redis',
            'config' => [
                'host'       => env('REDIS_HOST', 'localhost'),
                'port'       => (int) env('REDIS_PORT', 6379),
                'password'   => env('REDIS_PASSWORD'),
                'username'   => env('REDIS_USERNAME'),
                'database'   => (int) env('REDIS_CACHE_DB', 1),
                'key_prefix' => env('CACHE_REDIS_PREFIX', 'cache:'),
                'tag_prefix' => env('CACHE_REDIS_TAG_PREFIX', 'tag:'),
                'topic_prefix' => env('CACHE_REDIS_TOPIC_PREFIX', 'topic:'),
                'persistent' => env('REDIS_PERSISTENT', true),
                'persistent_id' => env('REDIS_PERSISTENT_ID', 'kariricode_cache'),
                'compression' => env('REDIS_COMPRESSION', true),
                'timeout'     => (float) env('REDIS_TIMEOUT', 2.0),
                'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 2.0),
                'pipeline_ops' => env('REDIS_PIPELINE_OPS', true),
                'lua_scripts' => env('REDIS_LUA_SCRIPTS', true),
            ],
        ],

        'memcached' => [
            'driver' => 'memcached',
            'config' => [
                'servers' => [[
                    'host'   => env('MEMCACHED_HOST', 'localhost'),
                    'port'   => (int) env('MEMCACHED_PORT', 11211),
                    'weight' => (int) env('MEMCACHED_WEIGHT', 100),
                ]],
                'persistent_id'      => env('MEMCACHED_PERSISTENT_ID', 'kariricode_cache'),
                'username'           => env('MEMCACHED_USERNAME'),
                'password'           => env('MEMCACHED_PASSWORD'),
                'key_prefix'         => env('CACHE_MEMCACHED_PREFIX', 'cache:'),
                'compression'        => env('MEMCACHED_COMPRESSION', true),
                'serializer'         => env('MEMCACHED_SERIALIZER', 'igbinary'),
                'binary_protocol'    => env('MEMCACHED_BINARY_PROTOCOL', true),
                'consistent_hashing' => env('MEMCACHED_CONSISTENT_HASHING', true),
                'tcp_nodelay'        => env('MEMCACHED_TCP_NODELAY', true),
                'buffer_writes'      => env('MEMCACHED_BUFFER_WRITES', true),
            ],
        ],

        'apcu' => [
            'driver' => 'apcu',
            'config' => [
                'key_prefix'          => env('CACHE_APCU_PREFIX', 'apcu:'),
                'tag_prefix'          => env('CACHE_APCU_TAG_PREFIX', 'tag:'),
                'topic_prefix'        => env('CACHE_APCU_TOPIC_PREFIX', 'topic:'),
                'enable_statistics'   => env('APCU_ENABLE_STATISTICS', true),
                'cleanup_probability' => (float) env('APCU_CLEANUP_PROBABILITY', 0.01),
            ],
        ],

        'filesystem' => [
            'driver' => 'filesystem',
            'config' => [
                'directory'           => env('CACHE_FILESYSTEM_DIR', storage_path('cache')),
                'key_prefix'          => env('CACHE_FILESYSTEM_PREFIX', 'fs_'),
                'max_size'            => (int) env('CACHE_FILESYSTEM_MAX_SIZE', 104857600),
                'cleanup_probability' => (float) env('CACHE_CLEANUP_PROBABILITY', 0.01),
                'use_locking'         => env('FILESYSTEM_FILE_LOCKING', true),
                'use_compression'     => env('FILESYSTEM_COMPRESSION', false),
                'directory_levels'    => (int) env('FILESYSTEM_DIR_LEVELS', 2),
                'file_permissions'    => 0644,
                'directory_permissions' => 0755,
            ],
        ],

        'array' => [
            'driver' => 'array',
            'config' => [],
        ],
    ],

    // ────────────────────────────────────────────────────────────────────────
    // Expiration Provider (Strategy pattern)
    // ────────────────────────────────────────────────────────────────────────
    'expiration_provider' => [
        'default_ttl' => (int) env('CACHE_DEFAULT_TTL', 3600),
        'operation_ttls' => [
            'set'            => (int) env('CACHE_SET_TTL', 1800),
            'setMultiple'    => (int) env('CACHE_SET_MULTIPLE_TTL', 1800),
            'add'            => (int) env('CACHE_ADD_TTL', 1800),
            'increment'      => (int) env('CACHE_INCREMENT_TTL', 7200),
            'decrement'      => (int) env('CACHE_DECREMENT_TTL', 7200),
            'setWithTags'    => (int) env('CACHE_TAGGED_TTL', 900),
            'compareAndSwap' => (int) env('CACHE_CAS_TTL', 1800),
        ],
        'key_pattern_ttls' => [
            'session:*'   => (int) env('SESSION_TTL', 7200),
            'api:*'       => (int) env('API_CACHE_TTL', 1800),
            'user:*'      => (int) env('USER_CACHE_TTL', 900),
            'temp:*'      => (int) env('TEMP_CACHE_TTL', 300),
            'config:*'    => (int) env('CONFIG_CACHE_TTL', 3600),
            'counter:*'   => (int) env('COUNTER_CACHE_TTL', 86400),
            'critical:*'  => (int) env('CRITICAL_CACHE_TTL', 60),
            'analytics:*' => (int) env('ANALYTICS_CACHE_TTL', 21600),
        ],
        'sliding_expiration' => env('CACHE_SLIDING_EXPIRATION', false),
        'max_ttl'            => (int) env('CACHE_MAX_TTL', 2592000), // 30 days
    ],

    // ────────────────────────────────────────────────────────────────────────
    // Serializer stack
    // ────────────────────────────────────────────────────────────────────────
    'serializers' => [
        'native'    => \KaririCode\Cache\Serializer\NativeSerializer::class,
        'igbinary'  => \KaririCode\Cache\Serializer\IgbinarySerializer::class,
        'json'      => \KaririCode\Cache\Serializer\JsonSerializer::class,
        'compressed' => [
            'class'             => \KaririCode\Cache\Serializer\CompressingSerializer::class,
            'inner'             => 'igbinary',
            'compression_level' => 6,
            'threshold'         => 1024,
        ],
    ],
    'serializer' => env('CACHE_SERIALIZER', 'igbinary'),

    // ────────────────────────────────────────────────────────────────────────
    // Feature Toggles
    // ────────────────────────────────────────────────────────────────────────
    'features' => [
        'debug_mode'          => env('CACHE_DEBUG_MODE', false),
        'stampede_protection' => env('CACHE_STAMPEDE_PROTECTION', true),
        'metrics_enabled'     => env('CACHE_METRICS_ENABLED', true),
        'events_enabled'      => env('CACHE_EVENTS_ENABLED', true),
        'strict_validation'   => env('CACHE_STRICT_VALIDATION', true),
        'compression_enabled' => env('CACHE_COMPRESSION_ENABLED', true),
        'async_operations'    => env('CACHE_ASYNC_OPERATIONS', false),
        'encryption_enabled'  => env('CACHE_ENCRYPTION_ENABLED', false),
        'encryption_key'      => env('CACHE_ENCRYPTION_KEY'),
    ],

    // ────────────────────────────────────────────────────────────────────────
    // Validation
    // ────────────────────────────────────────────────────────────────────────
    'validation' => [
        'max_key_length'      => (int) env('CACHE_MAX_KEY_LENGTH', 250),
        'max_value_size'      => (int) env('CACHE_MAX_VALUE_SIZE', 2097152),
        'reserved_characters' => ['{}()', '/', '@', ':'],
        'key_pattern'         => '/^[a-zA-Z0-9_\-.]+$/',
    ],

    // ────────────────────────────────────────────────────────────────────────
    // Metrics
    // ────────────────────────────────────────────────────────────────────────
    'metrics' => [
        'enabled'              => env('CACHE_METRICS_ENABLED', true),
        'collector'            => env('CACHE_METRICS_COLLECTOR', 'default'),
        'export_format'        => env('CACHE_METRICS_FORMAT', 'json'),
        'export_interval'      => (int) env('CACHE_METRICS_INTERVAL', 60),
        'include_driver_stats' => env('CACHE_METRICS_DRIVER_STATS', true),
    ],

    // ────────────────────────────────────────────────────────────────────────
    // Logging
    // ────────────────────────────────────────────────────────────────────────
    'logging' => [
        'enabled'            => env('CACHE_LOGGING_ENABLED', true),
        'channel'            => env('CACHE_LOG_CHANNEL', 'cache'),
        'level'              => env('CACHE_LOG_LEVEL', 'info'),
        'include_values'     => env('CACHE_LOG_VALUES', false),
        'max_value_length'   => (int) env('CACHE_LOG_MAX_VALUE_LENGTH', 100),
    ],

    // ────────────────────────────────────────────────────────────────────────
    // Health Checks
    // ────────────────────────────────────────────────────────────────────────
    'health_checks' => [
        'enabled'            => env('CACHE_HEALTH_CHECKS', true),
        'interval'           => (int) env('CACHE_HEALTH_CHECK_INTERVAL', 300),
        'timeout'            => (int) env('CACHE_HEALTH_CHECK_TIMEOUT', 5),
        'stores_to_check'    => array_filter(explode(',', env('CACHE_HEALTH_CHECK_STORES', 'redis,memcached'))),
        'failure_threshold'  => (int) env('CACHE_HEALTH_FAILURE_THRESHOLD', 3),
        'recovery_threshold' => (int) env('CACHE_HEALTH_RECOVERY_THRESHOLD', 2),
    ],

    // ────────────────────────────────────────────────────────────────────────
    // Driver‑specific optimizations
    // ────────────────────────────────────────────────────────────────────────
    'optimizations' => [
        'redis' => [
            'pipeline_batch_size' => (int) env('REDIS_PIPELINE_BATCH_SIZE', 100),
            'scan_count'          => (int) env('REDIS_SCAN_COUNT', 100),
            'lua_script_caching'  => env('REDIS_LUA_CACHE', true),
        ],
        'memcached' => [
            'cas_tokens' => env('MEMCACHED_CAS_TOKENS', true),
            'no_block'   => env('MEMCACHED_NO_BLOCK', true),
        ],
        'filesystem' => [
            'atomic_writes'    => env('FILESYSTEM_ATOMIC_WRITES', true),
            'metadata_caching' => env('FILESYSTEM_META_CACHE', true),
        ],
        'apcu' => [
            'slam_defense'   => env('APCU_SLAM_DEFENSE', true),
            'preload_enabled' => env('APCU_PRELOAD', false),
        ],
    ],
];

// Helper: resolves path within storage directory
if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        $basePath = dirname(__DIR__) . '/storage';
        return rtrim($basePath, '/') . ($path ? '/' . ltrim($path, '/') : '');
    }
}
