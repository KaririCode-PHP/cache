<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Defines the capabilities that a cache driver can support.
 *
 * This class contains constants representing various features and operations
 * that cache drivers may implement. It's used for capability discovery and
 * feature detection at runtime.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final abstract class CacheCapabilities
{
    // Basic Operations
    public const BASIC_OPERATIONS = 'basic_operations';
    public const BATCH_OPERATIONS = 'batch_operations';
    public const ATOMIC_OPERATIONS = 'atomic_operations';

    // Advanced Features
    public const TAGGING_OPERATIONS = 'tagging_operations';
    public const TOPICS_OPERATIONS = 'topics_operations';
    public const MAINTENANCE_OPERATIONS = 'maintenance_operations';

    // Layered Driver Specific
    public const LAYERED_OPERATIONS = 'layered_operations';
    public const CIRCUIT_BREAKER = 'circuit_breaker';
    public const CACHE_PROMOTION = 'cache_promotion';

    // Performance Features
    public const ASYNC_OPERATIONS = 'async_operations';
    public const PIPELINE_OPERATIONS = 'pipeline_operations';
    public const LUA_SCRIPTING = 'lua_scripting';

    // Data Features
    public const COMPRESSION = 'compression';
    public const ENCRYPTION = 'encryption';
    public const SERIALIZATION = 'serialization';

    // Reliability Features
    public const TRANSACTION_SUPPORT = 'transaction_support';
    public const STAMPEDE_PROTECTION = 'stampede_protection';
    public const SLIDING_EXPIRATION = 'sliding_expiration';

    // Monitoring
    public const STATISTICS = 'statistics';
    public const METRICS_COLLECTION = 'metrics_collection';
    public const HEALTH_CHECK = 'health_check';

    /**
     * Private constructor to prevent instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Get all available capabilities.
     *
     * @return array<string> List of all capability constants
     */
    public static function all(): array
    {
        return [
            self::BASIC_OPERATIONS,
            self::BATCH_OPERATIONS,
            self::ATOMIC_OPERATIONS,
            self::TAGGING_OPERATIONS,
            self::TOPICS_OPERATIONS,
            self::MAINTENANCE_OPERATIONS,
            self::LAYERED_OPERATIONS,
            self::CIRCUIT_BREAKER,
            self::CACHE_PROMOTION,
            self::ASYNC_OPERATIONS,
            self::PIPELINE_OPERATIONS,
            self::LUA_SCRIPTING,
            self::COMPRESSION,
            self::ENCRYPTION,
            self::SERIALIZATION,
            self::TRANSACTION_SUPPORT,
            self::STAMPEDE_PROTECTION,
            self::SLIDING_EXPIRATION,
            self::STATISTICS,
            self::METRICS_COLLECTION,
            self::HEALTH_CHECK,
        ];
    }

    /**
     * Check if a capability is valid.
     *
     * @param string $capability The capability to check
     * @return bool True if the capability is valid
     */
    public static function isValid(string $capability): bool
    {
        return in_array($capability, self::all(), true);
    }

    /**
     * Get capabilities grouped by category.
     *
     * @return array<string, array<string>> Capabilities grouped by category
     */
    public static function grouped(): array
    {
        return [
            'operations' => [
                self::BASIC_OPERATIONS,
                self::BATCH_OPERATIONS,
                self::ATOMIC_OPERATIONS,
            ],
            'advanced' => [
                self::TAGGING_OPERATIONS,
                self::TOPICS_OPERATIONS,
                self::MAINTENANCE_OPERATIONS,
            ],
            'layered' => [
                self::LAYERED_OPERATIONS,
                self::CIRCUIT_BREAKER,
                self::CACHE_PROMOTION,
            ],
            'performance' => [
                self::ASYNC_OPERATIONS,
                self::PIPELINE_OPERATIONS,
                self::LUA_SCRIPTING,
            ],
            'data' => [
                self::COMPRESSION,
                self::ENCRYPTION,
                self::SERIALIZATION,
            ],
            'reliability' => [
                self::TRANSACTION_SUPPORT,
                self::STAMPEDE_PROTECTION,
                self::SLIDING_EXPIRATION,
            ],
            'monitoring' => [
                self::STATISTICS,
                self::METRICS_COLLECTION,
                self::HEALTH_CHECK,
            ],
        ];
    }
}
