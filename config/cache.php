<?php

declare(strict_types=1);

use function KaririCode\Dotenv\env;

/**
 * KaririCode Cache Configuration.
 *
 * This file contains all cache configuration for the application.
 * It follows best practices for configuration management and
 * supports multiple environments (development, staging, production).
 *
 * @category  Cache
 * @package   KaririCode\Cache
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 * @link      https://kariricode.org/
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This store will be used when no specific store is specified.
    |
    */
    'default' => env('CACHE_DEFAULT_STORE', 'layered'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all cache stores for your application. Each store
    | can use a different driver and have its own configuration.
    |
    */
    'stores' => [
        // Layered cache for production (L1: APCu, L2: Redis, L3: Filesystem)
        'layered' => [
            'driver' => 'layered',
            'layers' => [
                [
                    'driver' => 'apcu',
                    'priority' => 1, // Highest priority (L1)
                    'config' => [
                        'key_prefix' => env('CACHE_APCU_PREFIX', 'l1:'),
                    ],
                ],
                [
                    'driver' => 'redis',
                    'priority' => 10, // Medium priority (L2)
                    'config' => [
                        'host' => env('REDIS_HOST', 'localhost'),
                        'port' => (int) env('REDIS_PORT', 6379),
                        'password' => env('REDIS_PASSWORD'),
                        'database' => (int) env('REDIS_CACHE_DB', 1),
                        'key_prefix' => env('CACHE_REDIS_PREFIX', 'l2:'),
                    ],
                ],
                [
                    'driver' => 'filesystem',
                    'priority' => 90, // Lowest priority (L3)
                    'config' => [
                        'directory' => env('CACHE_FILESYSTEM_DIR', storage_path('cache')),
                        'key_prefix' => env('CACHE_FILESYSTEM_PREFIX', 'l3_'),
                    ],
                ],
            ],
        ],

        // Redis store for distributed caching
        'redis' => [
            'driver' => 'redis',
            'config' => [
                'host' => env('REDIS_HOST', 'localhost'),
                'port' => (int) env('REDIS_PORT', 6379),
                'password' => env('REDIS_PASSWORD'),
                'username' => env('REDIS_USERNAME'),
                'database' => (int) env('REDIS_CACHE_DB', 1),
                'key_prefix' => env('CACHE_REDIS_PREFIX', 'cache:'),
                'tag_prefix' => env('CACHE_REDIS_TAG_PREFIX', 'tag:'),
                'topic_prefix' => env('CACHE_REDIS_TOPIC_PREFIX', 'topic:'),
                'persistent' => env('REDIS_PERSISTENT', true),
                'persistent_id' => env('REDIS_PERSISTENT_ID', 'kariricode_cache'),
                'compression' => env('REDIS_COMPRESSION', true),
                'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
                'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 2.0),
                'pipeline_ops' => env('REDIS_PIPELINE_OPS', true),
                'lua_scripts' => env('REDIS_LUA_SCRIPTS', true),
            ],
        ],

        // Memcached store for high-speed distributed caching
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
                'username' => env('MEMCACHED_USERNAME'),
                'password' => env('MEMCACHED_PASSWORD'),
                'key_prefix' => env('CACHE_MEMCACHED_PREFIX', 'cache:'),
                'compression' => env('MEMCACHED_COMPRESSION', true),
                'serializer' => env('MEMCACHED_SERIALIZER', 'igbinary'),
                'binary_protocol' => env('MEMCACHED_BINARY_PROTOCOL', true),
                'consistent_hashing' => env('MEMCACHED_CONSISTENT_HASHING', true),
                'tcp_nodelay' => env('MEMCACHED_TCP_NODELAY', true),
                'buffer_writes' => env('MEMCACHED_BUFFER_WRITES', true),
            ],
        ],

        // APCu store for local in-memory caching
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

        // Filesystem store for persistent caching
        'filesystem' => [
            'driver' => 'filesystem',
            'config' => [
                'directory' => env('CACHE_FILESYSTEM_DIR', storage_path('cache')),
                'key_prefix' => env('CACHE_FILESYSTEM_PREFIX', 'fs_'),
                'max_size' => (int) env('CACHE_FILESYSTEM_MAX_SIZE', 104857600), // 100MB
                'cleanup_probability' => (float) env('CACHE_CLEANUP_PROBABILITY', 0.01),
                'use_locking' => env('FILESYSTEM_FILE_LOCKING', true),
                'use_compression' => env('FILESYSTEM_COMPRESSION', false),
                'directory_levels' => (int) env('FILESYSTEM_DIR_LEVELS', 2),
                'file_permissions' => 0644,
                'directory_permissions' => 0755,
            ],
        ],

        // Array store for testing and development
        'array' => [
            'driver' => 'array',
            'config' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Expiration Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the global expiration provider that manages TTLs for all
    | cache operations. This uses the Strategy Pattern for flexible TTL
    | management across different operations and key patterns.
    |
    */
    'expiration_provider' => [
        // Default TTL in seconds (1 hour)
        'default_ttl' => (int) env('CACHE_DEFAULT_TTL', 3600),

        // Operation-specific TTLs
        'operation_ttls' => [
            'set' => (int) env('CACHE_SET_TTL', 1800),              // 30 minutes
            'setMultiple' => (int) env('CACHE_SET_MULTIPLE_TTL', 1800), // 30 minutes
            'add' => (int) env('CACHE_ADD_TTL', 1800),              // 30 minutes
            'increment' => (int) env('CACHE_INCREMENT_TTL', 7200),   // 2 hours
            'decrement' => (int) env('CACHE_DECREMENT_TTL', 7200),   // 2 hours
            'setWithTags' => (int) env('CACHE_TAGGED_TTL', 900),    // 15 minutes
            'compareAndSwap' => (int) env('CACHE_CAS_TTL', 1800),   // 30 minutes
        ],

        // Key pattern-specific TTLs
        'key_pattern_ttls' => [
            'session:*' => (int) env('SESSION_TTL', 7200),          // 2 hours
            'api:*' => (int) env('API_CACHE_TTL', 1800),           // 30 minutes
            'user:*' => (int) env('USER_CACHE_TTL', 900),          // 15 minutes
            'temp:*' => (int) env('TEMP_CACHE_TTL', 300),          // 5 minutes
            'config:*' => (int) env('CONFIG_CACHE_TTL', 3600),     // 1 hour
            'counter:*' => (int) env('COUNTER_CACHE_TTL', 86400),  // 24 hours
            'critical:*' => (int) env('CRITICAL_CACHE_TTL', 60),   // 1 minute
            'analytics:*' => (int) env('ANALYTICS_CACHE_TTL', 21600), // 6 hours
        ],

        // Enable sliding expiration (TTL resets on access)
        'sliding_expiration' => env('CACHE_SLIDING_EXPIRATION', false),

        // Maximum allowed TTL (30 days)
        'max_ttl' => (int) env('CACHE_MAX_TTL', 2592000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Serializers Configuration
    |--------------------------------------------------------------------------
    |
    | Configure available serializers for cache data. The framework supports
    | multiple serialization strategies for different use cases.
    |
    */
    'serializers' => [
        'native' => \KaririCode\Cache\Serializer\NativeSerializer::class,
        'igbinary' => \KaririCode\Cache\Serializer\IgbinarySerializer::class,
        'json' => \KaririCode\Cache\Serializer\JsonSerializer::class,
        'compressed' => [
            'class' => \KaririCode\Cache\Serializer\CompressingSerializer::class,
            'inner' => 'igbinary',
            'compression_level' => 6,
            'threshold' => 1024, // Only compress data larger than 1KB
        ],
    ],

    // Default serializer
    'serializer' => env('CACHE_SERIALIZER', 'igbinary'),

    /*
    |--------------------------------------------------------------------------
    | Feature Toggles
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific cache features globally.
    |
    */
    'features' => [
        'stampede_protection' => env('CACHE_STAMPEDE_PROTECTION', true),
        'metrics_enabled' => env('CACHE_METRICS_ENABLED', true),
        'events_enabled' => env('CACHE_EVENTS_ENABLED', true),
        'strict_validation' => env('CACHE_STRICT_VALIDATION', true),
        'compression_enabled' => env('CACHE_COMPRESSION_ENABLED', true),
        'async_operations' => env('CACHE_ASYNC_OPERATIONS', false),
        'debug_mode' => env('CACHE_DEBUG_MODE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    |
    | Configure validation rules for cache operations.
    |
    */
    'validation' => [
        'max_key_length' => (int) env('CACHE_MAX_KEY_LENGTH', 250),
        'max_value_size' => (int) env('CACHE_MAX_VALUE_SIZE', 2097152), // 2MB
        'reserved_characters' => ['{}()', '/', '@', ':'], // PSR-6 reserved
        'key_pattern' => '/^[a-zA-Z0-9_\-\.]+$/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Configuration
    |--------------------------------------------------------------------------
    |
    | Configure metrics collection and reporting.
    |
    */
    'metrics' => [
        'enabled' => env('CACHE_METRICS_ENABLED', true),
        'collector' => env('CACHE_METRICS_COLLECTOR', 'default'),
        'export_format' => env('CACHE_METRICS_FORMAT', 'json'),
        'export_interval' => (int) env('CACHE_METRICS_INTERVAL', 60),
        'include_driver_stats' => env('CACHE_METRICS_DRIVER_STATS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure cache-specific logging.
    |
    */
    'logging' => [
        'enabled' => env('CACHE_LOGGING_ENABLED', true),
        'channel' => env('CACHE_LOG_CHANNEL', 'cache'),
        'level' => env('CACHE_LOG_LEVEL', 'info'),
        'include_values' => env('CACHE_LOG_VALUES', false),
        'max_value_length' => (int) env('CACHE_LOG_MAX_VALUE_LENGTH', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Configuration
    |--------------------------------------------------------------------------
    |
    | Configure health checks for cache stores.
    |
    */
    'health_checks' => [
        'enabled' => env('CACHE_HEALTH_CHECKS', true),
        'interval' => (int) env('CACHE_HEALTH_CHECK_INTERVAL', 300), // 5 minutes
        'timeout' => (int) env('CACHE_HEALTH_CHECK_TIMEOUT', 5),
        'stores_to_check' => array_filter(
            explode(',', env('CACHE_HEALTH_CHECK_STORES', 'redis,memcached'))
        ),
        'failure_threshold' => (int) env('CACHE_HEALTH_FAILURE_THRESHOLD', 3),
        'recovery_threshold' => (int) env('CACHE_HEALTH_RECOVERY_THRESHOLD', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Driver-Specific Optimizations
    |--------------------------------------------------------------------------
    |
    | Fine-tune specific driver behaviors.
    |
    */
    'optimizations' => [
        'redis' => [
            'pipeline_batch_size' => (int) env('REDIS_PIPELINE_BATCH_SIZE', 100),
            'scan_count' => (int) env('REDIS_SCAN_COUNT', 100),
            'lua_script_caching' => env('REDIS_LUA_CACHE', true),
        ],
        'memcached' => [
            'cas_tokens' => env('MEMCACHED_CAS_TOKENS', true),
            'no_block' => env('MEMCACHED_NO_BLOCK', true),
        ],
        'filesystem' => [
            'atomic_writes' => env('FILESYSTEM_ATOMIC_WRITES', true),
            'metadata_caching' => env('FILESYSTEM_META_CACHE', true),
        ],
        'apcu' => [
            'slam_defense' => env('APCU_SLAM_DEFENSE', true),
            'preload_enabled' => env('APCU_PRELOAD', false),
        ],
    ],
];

/**
 * Helper function for storage path (if not already defined).
 */
if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        $basePath = dirname(__DIR__) . '/storage';
        return rtrim($basePath, '/') . ($path ? '/' . ltrim($path, '/') : '');
    }
}

/**
 * Helper function for env variables (if not already defined).
 */
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        // Convert string boolean values
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }

        // Handle quoted strings
        if (preg_match('/^"(.*)"$/', $value, $matches)) {
            return $matches[1];
        }

        return $value;
    }
}
