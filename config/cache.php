<?php

declare(strict_types=1);

use function KaririCode\Dotenv\env;

/**
 * Improved cache configuration that maximizes the use of KaririCode\Configurator
 * features while maintaining clean separation of concerns.
 *
 * This configuration file demonstrates:
 * - Environment variable support with defaults
 * - Hierarchical configuration structure
 * - Feature toggles
 * - Driver-specific optimizations
 * - Security and observability settings
 */

return [
    // =============================================================================
    // CORE CACHE SETTINGS
    // =============================================================================

    'default' => env('CACHE_DEFAULT_STORE', 'layered'),

    // =============================================================================
    // CACHE STORES CONFIGURATION
    // =============================================================================

    'stores' => [

        // High-performance layered cache for production
        'layered' => [
            'driver' => 'layered',
            'layers' => [
                // L1: APCu for ultra-fast local access
                [
                    'driver' => 'apcu',
                    'priority' => 5,
                    'config' => [
                        'key_prefix' => env('CACHE_APCU_PREFIX', 'l1:'),
                    ],
                ],

                // L2: Redis for distributed caching
                [
                    'driver' => 'redis',
                    'priority' => 10,
                    'config' => [
                        'host' => env('REDIS_HOST', 'localhost'),
                        'port' => (int) env('REDIS_PORT', 6379),
                        'password' => env('REDIS_PASSWORD', null),
                        'database' => (int) env('REDIS_CACHE_DB', 1),
                        'key_prefix' => env('CACHE_REDIS_PREFIX', 'l2:'),
                        'tag_prefix' => env('CACHE_REDIS_TAG_PREFIX', 'tag:'),
                        'topic_prefix' => env('CACHE_REDIS_TOPIC_PREFIX', 'topic:'),
                        'persistent' => env('REDIS_PERSISTENT', true),
                        'compression' => env('REDIS_COMPRESSION', true),
                        'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
                        'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 2.0),
                    ],
                ],

                // L3: Filesystem for persistence and fallback
                [
                    'driver' => 'filesystem',
                    'priority' => 90,
                    'config' => [
                        'directory' => env('CACHE_FILESYSTEM_DIR', storage_path('cache')),
                        'key_prefix' => env('CACHE_FILESYSTEM_PREFIX', 'l3_'),
                        'max_size' => (int) env('CACHE_FILESYSTEM_MAX_SIZE', 104857600), // 100MB
                        'cleanup_probability' => (float) env('CACHE_CLEANUP_PROBABILITY', 0.01),
                    ],
                ],
            ],
        ],

        // Dedicated Redis store for specific use cases
        'redis' => [
            'driver' => 'redis',
            'config' => [
                'host' => env('REDIS_HOST', 'localhost'),
                'port' => (int) env('REDIS_PORT', 6379),
                'password' => env('REDIS_PASSWORD', null),
                'database' => (int) env('REDIS_CACHE_DB', 1),
                'username' => env('REDIS_USERNAME', null),
                'key_prefix' => env('CACHE_REDIS_PREFIX', 'cache:'),
                'tag_prefix' => env('CACHE_REDIS_TAG_PREFIX', 'tag:'),
                'topic_prefix' => env('CACHE_REDIS_TOPIC_PREFIX', 'topic:'),
                'persistent' => env('REDIS_PERSISTENT', true),
                'persistent_id' => env('REDIS_PERSISTENT_ID', 'kariricode_cache'),
                'compression' => env('REDIS_COMPRESSION', true),
                'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
                'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 2.0),
            ],
        ],

        // High-performance Memcached store
        'memcached' => [
            'driver' => 'memcached',
            'config' => [
                'servers' => [
                    [
                        env('MEMCACHED_HOST', 'localhost'),
                        (int) env('MEMCACHED_PORT', 11211),
                        (int) env('MEMCACHED_WEIGHT', 100),
                    ],
                ],
                'persistent_id' => env('MEMCACHED_PERSISTENT_ID', 'kariricode_cache'),
                'username' => env('MEMCACHED_USERNAME', null),
                'password' => env('MEMCACHED_PASSWORD', null),
                'compression' => env('MEMCACHED_COMPRESSION', true),
                'serializer' => env('MEMCACHED_SERIALIZER', 'igbinary'),
                'key_prefix' => env('CACHE_MEMCACHED_PREFIX', 'cache:'),
                'connect_timeout' => (int) env('MEMCACHED_CONNECT_TIMEOUT', 1),
                'poll_timeout' => (int) env('MEMCACHED_POLL_TIMEOUT', 1),
                'recv_timeout' => (int) env('MEMCACHED_RECV_TIMEOUT', 1),
                'send_timeout' => (int) env('MEMCACHED_SEND_TIMEOUT', 1),
                'options' => [
                    // Additional Memcached options can be set here
                ],
            ],
        ],

        // Filesystem cache for development and fallback
        'filesystem' => [
            'driver' => 'filesystem',
            'config' => [
                'directory' => env('CACHE_FILESYSTEM_DIR', storage_path('cache')),
                'key_prefix' => env('CACHE_FILESYSTEM_PREFIX', 'fs_'),
                'max_size' => (int) env('CACHE_FILESYSTEM_MAX_SIZE', 104857600), // 100MB
                'cleanup_probability' => (float) env('CACHE_CLEANUP_PROBABILITY', 0.01),
            ],
        ],

        // APCu for ultra-fast local caching
        'apcu' => [
            'driver' => 'apcu',
            'config' => [
                'key_prefix' => env('CACHE_APCU_PREFIX', 'apcu:'),
            ],
        ],

        // In-memory array cache for testing
        'array' => [
            'driver' => 'array',
            'config' => [],
        ],

        // Session-specific cache configuration
        'sessions' => [
            'driver' => 'redis',
            'config' => [
                'host' => env('SESSION_REDIS_HOST', env('REDIS_HOST', 'localhost')),
                'port' => (int) env('SESSION_REDIS_PORT', env('REDIS_PORT', 6379)),
                'password' => env('SESSION_REDIS_PASSWORD', env('REDIS_PASSWORD', null)),
                'database' => (int) env('SESSION_REDIS_DB', 2),
                'key_prefix' => env('SESSION_CACHE_PREFIX', 'sess:'),
                'persistent' => true,
                'compression' => false, // Sessions are usually small
                'timeout' => 1.0,
            ],
        ],

        // API response cache
        'api_responses' => [
            'driver' => 'layered',
            'layers' => [
                [
                    'driver' => 'apcu',
                    'priority' => 5,
                    'config' => [
                        'key_prefix' => 'api:l1:',
                    ],
                ],
                [
                    'driver' => 'redis',
                    'priority' => 15,
                    'config' => [
                        'host' => env('REDIS_HOST', 'localhost'),
                        'port' => (int) env('REDIS_PORT', 6379),
                        'database' => (int) env('API_CACHE_REDIS_DB', 3),
                        'key_prefix' => 'api:l2:',
                        'compression' => true,
                        'timeout' => 1.0,
                    ],
                ],
            ],
        ],
    ],

    // =============================================================================
    // SERIALIZATION CONFIGURATION
    // =============================================================================

    'serializer' => env('CACHE_SERIALIZER', 'native'), // native, igbinary, json, compressed, compressed_igbinary

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
    ],

    // =============================================================================
    // DEFAULT SETTINGS
    // =============================================================================

    'defaults' => [
        'ttl' => (int) env('CACHE_DEFAULT_TTL', 3600), // 1 hour
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
        'max_value_size' => (int) env('CACHE_MAX_VALUE_SIZE', 1048576), // 1MB
    ],

    // =============================================================================
    // OBSERVABILITY AND MONITORING
    // =============================================================================

    'observability' => [
        'enable_performance_tracking' => env('CACHE_PERFORMANCE_TRACKING', true),
        'enable_security_monitoring' => env('CACHE_SECURITY_MONITORING', false),
        'max_events' => (int) env('CACHE_MAX_EVENTS', 1000),
        'slow_threshold' => (float) env('CACHE_SLOW_THRESHOLD', 0.1), // 100ms
        'enable_debug_logging' => env('CACHE_DEBUG_LOGGING', false),
        'metrics_collection_interval' => (int) env('CACHE_METRICS_INTERVAL', 60), // seconds
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
                'strict_validation' => false, // More lenient for development
            ],
            'observability' => [
                'enable_debug_logging' => true,
                'slow_threshold' => 0.01, // More sensitive in development
            ],
        ],

        'testing' => [
            'default' => 'array',
            'features' => [
                'stampede_protection' => false, // Simplify for tests
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
                'slow_threshold' => 0.2, // Less sensitive in production
            ],
        ],
    ],

    // =============================================================================
    // SPECIALIZED CACHE CONFIGURATIONS
    // =============================================================================

    'specialized' => [

        // Rate limiting cache
        'rate_limiting' => [
            'store' => 'redis',
            'prefix' => 'rate_limit:',
            'default_window' => 3600, // 1 hour
            'default_limit' => 1000,
        ],

        // API response cache
        'api_responses' => [
            'store' => 'api_responses',
            'prefix' => 'api:',
            'default_ttl' => 1800, // 30 minutes
            'max_response_size' => 1048576, // 1MB
            'compress_responses' => true,
            'cache_headers' => true,
            'vary_on_headers' => ['Accept', 'Accept-Language', 'Authorization'],
        ],

        // Session cache
        'sessions' => [
            'store' => 'sessions',
            'prefix' => 'session:',
            'default_ttl' => 7200, // 2 hours
        ],

        // File cache for compiled views/templates
        'compiled' => [
            'store' => 'filesystem',
            'prefix' => 'compiled:',
            'default_ttl' => 86400, // 24 hours
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
    ],

    // =============================================================================
    // HEALTH CHECK CONFIGURATION
    // =============================================================================

    'health_checks' => [
        'enabled' => env('CACHE_HEALTH_CHECKS', true),
        'interval' => (int) env('CACHE_HEALTH_CHECK_INTERVAL', 300), // 5 minutes
        'timeout' => (int) env('CACHE_HEALTH_CHECK_TIMEOUT', 5), // 5 seconds
        'stores_to_check' => array_filter(
            explode(',', env('CACHE_HEALTH_CHECK_STORES', 'redis,memcached'))
        ),
    ],
];


/**
 * Helper function for storage path
 */
if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        $basePath =  __DIR__ . '/../storage';
        return rtrim($basePath, '/') . ($path ? '/' . ltrim($path, '/') : '');
    }
}
