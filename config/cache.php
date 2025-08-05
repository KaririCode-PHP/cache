<?php

declare(strict_types=1);

use function KaririCode\Dotenv\env;

/**
 * KaririCode Cache Configuration
 *
 * Follows SOLID principles with clean separation of concerns.
 * Uses environment variables with sensible defaults.
 */
return [
    // =============================================================================
    // CORE CACHE SETTINGS
    // =============================================================================

    'default' => env('CACHE_DEFAULT_STORE', 'layered'),
    'serializer' => env('CACHE_SERIALIZER', 'igbinary'),

    // =============================================================================
    // EXPIRATION PROVIDER CONFIGURATION (Strategy Pattern)
    // =============================================================================
    'expiration' => [
        'provider' => \KaririCode\Cache\Provider\ConfigurableExpirationProvider::class,
        'default_ttl' => (int) env('CACHE_DEFAULT_TTL', 3600),

        // Operation-specific TTLs
        'operation_ttls' => [
            'set' => (int) env('CACHE_SET_TTL', 3600),
            'setMultiple' => (int) env('CACHE_SET_MULTIPLE_TTL', 3600),
            'add' => (int) env('CACHE_ADD_TTL', 3600),
            'increment' => (int) env('CACHE_INCREMENT_TTL', 7200),
            'decrement' => (int) env('CACHE_DECREMENT_TTL', 7200),
            'setWithTags' => (int) env('CACHE_TAGGED_TTL', 1800),
            'compareAndSwap' => (int) env('CACHE_CAS_TTL', 1800),
        ],

        // Key pattern-based TTLs
        'key_pattern_ttls' => [
            'session:*' => (int) env('SESSION_TTL', 7200),
            'api:*' => (int) env('API_CACHE_TTL', 1800),
            'user:*' => (int) env('USER_CACHE_TTL', 900),
            'temp:*' => (int) env('TEMP_CACHE_TTL', 300),
            'config:*' => (int) env('CONFIG_CACHE_TTL', 86400),
            'counter:*' => (int) env('COUNTER_CACHE_TTL', 86400),
        ],
    ],

    // =============================================================================
    // CACHE STORES CONFIGURATION
    // =============================================================================
    'stores' => [
        // Production layered cache with ExpirationProvider
        'layered' => [
            'driver' => 'layered',
            'layers' => [
                [
                    'driver' => 'apcu',
                    'priority' => 5,
                    'config' => [
                        'key_prefix' => env('CACHE_APCU_PREFIX', 'l1:'),
                        'expiration_provider' => null, // Will be injected by factory
                    ],
                ],
                [
                    'driver' => 'redis',
                    'priority' => 10,
                    'config' => [
                        'host' => env('REDIS_HOST', 'localhost'),
                        'port' => (int) env('REDIS_PORT', 6379),
                        'key_prefix' => env('CACHE_REDIS_PREFIX', 'l2:'),
                        'expiration_provider' => null, // Will be injected by factory
                    ],
                ],
                [
                    'driver' => 'filesystem',
                    'priority' => 90,
                    'config' => [
                        'directory' => env('CACHE_FILESYSTEM_DIR', storage_path('cache')),
                        'key_prefix' => env('CACHE_FILESYSTEM_PREFIX', 'l3_'),
                        'expiration_provider' => null, // Will be injected by factory
                    ],
                ],
            ],
        ],

        // Dedicated Redis store
        'redis' => [
            'driver' => 'redis',
            'config' => [
                'host' => env('REDIS_HOST', 'localhost'),
                'port' => (int) env('REDIS_PORT', 6379),
                'password' => env('REDIS_PASSWORD', null),
                'database' => (int) env('REDIS_CACHE_DB', 1),
                'key_prefix' => env('CACHE_REDIS_PREFIX', 'cache:'),
                'tag_prefix' => env('CACHE_REDIS_TAG_PREFIX', 'tag:'),
                'topic_prefix' => env('CACHE_REDIS_TOPIC_PREFIX', 'topic:'),
                'persistent' => env('REDIS_PERSISTENT', true),
                'compression' => env('REDIS_COMPRESSION', true),
                'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
                'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 2.0),
            ],
        ],

        // High-performance APCu store
        'apcu' => [
            'driver' => 'apcu',
            'config' => [
                'key_prefix' => env('CACHE_APCU_PREFIX', 'apcu:'),
                'tag_prefix' => env('CACHE_APCU_TAG_PREFIX', 'tag:'),
                'topic_prefix' => env('CACHE_APCU_TOPIC_PREFIX', 'topic:'),
                'enable_statistics' => env('APCU_ENABLE_STATISTICS', true),
                'cleanup_probability' => (float) env('APCU_CLEANUP_PROBABILITY', 0.01),
            ],
        ],

        // Memcached store
        'memcached' => [
            'driver' => 'memcached',
            'config' => [
                'servers' => [
                    [
                        'host' => env('MEMCACHED_HOST', 'localhost'),
                        'port' => (int) env('MEMCACHED_PORT', 11211),
                        'weight' => (int) env('MEMCACHED_WEIGHT', 100),
                    ],
                ],
                'persistent_id' => env('MEMCACHED_PERSISTENT_ID', 'kariricode_cache'),
                'key_prefix' => env('CACHE_MEMCACHED_PREFIX', 'cache:'),
                'compression' => env('MEMCACHED_COMPRESSION', true),
                'serializer' => env('MEMCACHED_SERIALIZER', 'igbinary'),
                'binary_protocol' => env('MEMCACHED_BINARY_PROTOCOL', true),
                'consistent_hashing' => env('MEMCACHED_CONSISTENT_HASHING', true),
            ],
        ],

        // Filesystem store
        'filesystem' => [
            'driver' => 'filesystem',
            'config' => [
                'directory' => env('CACHE_FILESYSTEM_DIR', storage_path('cache')),
                'key_prefix' => env('CACHE_FILESYSTEM_PREFIX', 'fs_'),
                'max_size' => (int) env('CACHE_FILESYSTEM_MAX_SIZE', 104857600),
                'cleanup_probability' => (float) env('CACHE_CLEANUP_PROBABILITY', 0.01),
                'use_locking' => env('FILESYSTEM_FILE_LOCKING', true),
                'use_compression' => env('FILESYSTEM_COMPRESSION', false),
                'directory_levels' => (int) env('FILESYSTEM_DIR_LEVELS', 2),
            ],
        ],

        // Array store (for testing)
        'array' => [
            'driver' => 'array',
            'config' => [
                'default_ttl' => 3600,
            ],
        ],
    ],

    // =============================================================================
    // SERIALIZERS CONFIGURATION
    // =============================================================================

    'serializers' => [
        'native' => \KaririCode\Cache\Serializer\NativeSerializer::class,
        'igbinary' => \KaririCode\Cache\Serializer\IgbinarySerializer::class,
        'json' => \KaririCode\Cache\Serializer\JsonSerializer::class,
        'compressed' => [
            'class' => \KaririCode\Cache\Serializer\CompressingSerializer::class,
            'inner' => 'igbinary',
        ],
        'compressed_native' => [
            'class' => \KaririCode\Cache\Serializer\CompressingSerializer::class,
            'inner' => 'native',
        ],
    ],

    // =============================================================================
    // FEATURE TOGGLES
    // =============================================================================

    'features' => [
        'stampede_protection' => env('CACHE_STAMPEDE_PROTECTION', true),
        'metrics_enabled' => env('CACHE_METRICS_ENABLED', true),
        'sliding_expiration' => env('CACHE_SLIDING_EXPIRATION', false),
        'events_enabled' => env('CACHE_EVENTS_ENABLED', true),
        'psr14_events_enabled' => env('CACHE_PSR14_EVENTS_ENABLED', false),
        'strict_validation' => env('CACHE_STRICT_VALIDATION', true),
        'compression_enabled' => env('CACHE_COMPRESSION_ENABLED', true),
    ],

    // =============================================================================
    // DEFAULT SETTINGS
    // =============================================================================

    'defaults' => [
        'key_prefix' => env('CACHE_DEFAULT_PREFIX', ''),
        'namespace_separator' => env('CACHE_NAMESPACE_SEPARATOR', ':'),
    ],

    // =============================================================================
    // SECURITY CONFIGURATION
    // =============================================================================

    'security' => [
        'rate_limit' => (int) env('CACHE_RATE_LIMIT', 1000),
        'whitelisted_ips' => array_filter(
            explode(',', env('CACHE_WHITELISTED_IPS', '127.0.0.1,::1'))
        ),
        'audit_salt' => env('CACHE_AUDIT_SALT', 'cache_security_salt_change_in_production'),
        'enable_geo_location' => env('CACHE_ENABLE_GEO_LOCATION', false),
        'retention_days' => (int) env('CACHE_AUDIT_RETENTION_DAYS', 30),
        'max_key_length' => (int) env('CACHE_MAX_KEY_LENGTH', 250),
        'max_value_size' => (int) env('CACHE_MAX_VALUE_SIZE', 1048576),
    ],

    // =============================================================================
    // OBSERVABILITY AND MONITORING
    // =============================================================================

    'observability' => [
        'enable_performance_tracking' => env('CACHE_PERFORMANCE_TRACKING', true),
        'enable_security_monitoring' => env('CACHE_SECURITY_MONITORING', false),
        'max_events' => (int) env('CACHE_MAX_EVENTS', 1000),
        'slow_threshold' => (float) env('CACHE_SLOW_THRESHOLD', 0.1),
        'enable_debug_logging' => env('CACHE_DEBUG_LOGGING', false),
        'metrics_collection_interval' => (int) env('CACHE_METRICS_INTERVAL', 60),
    ],

    // =============================================================================
    // ENVIRONMENT-SPECIFIC OVERRIDES
    // =============================================================================

    'environments' => [
        'local' => [
            'default' => 'array',
            'features' => [
                'metrics_enabled' => true,
                'events_enabled' => true,
                'strict_validation' => false,
            ],
            'observability' => [
                'enable_debug_logging' => true,
                'slow_threshold' => 0.01,
            ],
        ],

        'testing' => [
            'default' => 'array',
            'features' => [
                'stampede_protection' => false,
                'metrics_enabled' => false,
                'events_enabled' => false,
            ],
        ],

        'staging' => [
            'features' => [
                'security_monitoring' => true,
                'performance_tracking' => true,
            ],
            'observability' => [
                'enable_debug_logging' => true,
            ],
        ],

        'production' => [
            'features' => [
                'security_monitoring' => true,
                'performance_tracking' => true,
                'strict_validation' => true,
            ],
            'security' => [
                'enable_geo_location' => true,
                'retention_days' => 90,
            ],
            'observability' => [
                'enable_debug_logging' => false,
                'slow_threshold' => 0.2,
            ],
        ],
    ],

    // =============================================================================
    // DRIVER-SPECIFIC OPTIMIZATIONS
    // =============================================================================

    'optimizations' => [
        'redis' => [
            'pipeline_operations' => env('REDIS_PIPELINE_OPS', true),
            'cluster_mode' => env('REDIS_CLUSTER_MODE', false),
            'sentinel_mode' => env('REDIS_SENTINEL_MODE', false),
            'max_connections' => (int) env('REDIS_MAX_CONNECTIONS', 20),
        ],

        'memcached' => [
            'consistent_hashing' => env('MEMCACHED_CONSISTENT_HASHING', true),
            'binary_protocol' => env('MEMCACHED_BINARY_PROTOCOL', true),
            'tcp_nodelay' => env('MEMCACHED_TCP_NODELAY', true),
        ],

        'filesystem' => [
            'atomic_writes' => env('FILESYSTEM_ATOMIC_WRITES', true),
            'file_locking' => env('FILESYSTEM_FILE_LOCKING', true),
            'directory_levels' => (int) env('FILESYSTEM_DIR_LEVELS', 2),
        ],

        'apcu' => [
            'enable_serializer' => env('APCU_ENABLE_SERIALIZER', true),
            'slam_defense' => env('APCU_SLAM_DEFENSE', true),
        ],
    ],

    // =============================================================================
    // HEALTH CHECK CONFIGURATION
    // =============================================================================

    'health_checks' => [
        'enabled' => env('CACHE_HEALTH_CHECKS', true),
        'interval' => (int) env('CACHE_HEALTH_CHECK_INTERVAL', 300),
        'timeout' => (int) env('CACHE_HEALTH_CHECK_TIMEOUT', 5),
        'stores_to_check' => array_filter(
            explode(',', env('CACHE_HEALTH_CHECK_STORES', 'redis,memcached'))
        ),
        'failure_threshold' => (int) env('CACHE_HEALTH_FAILURE_THRESHOLD', 3),
        'recovery_threshold' => (int) env('CACHE_HEALTH_RECOVERY_THRESHOLD', 2),
    ],
];

/**
 * Helper function for storage path
 */
if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        $basePath = __DIR__ . '/../storage';
        return rtrim($basePath, '/') . ($path ? '/' . ltrim($path, '/') : '');
    }
}
