<?php

declare(strict_types=1);

namespace KaririCode\Cache\Configuration;

use KaririCode\Cache\Exception\ConfigurationException;

/**
 * Validated options of FilesystemAdapter: file and directory modes,
 * sharding depth and garbage-collection probability.
 *
 * Replaces five scalar parameters of the adapter's constructor (Spec V4
 * §13). Garbage collection runs on a write with probability
 * gcProbability / gcDivisor; gcProbability 0 disables it.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): immutable once built.
 * Spec V4: DPO (final readonly class, self-validating, no behaviour).
 *
 * Preconditions: modes 0..07777; shardingLevels 0..8; gcProbability >= 0; gcDivisor >= 1.
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     $options = new FilesystemOptions(shardingLevels: 3, gcDivisor: 10_000);
 *
 * References: ADR-010; SPEC-001 §7.
 *
 * @see https://www.php.net/manual/en/function.chmod.php
 */
final readonly class FilesystemOptions
{
    /**
     * @param int $shardingLevels Directory levels, 0..8 (two hex characters each).
     */
    public function __construct(
        public int $directoryPermissions = 0o755,
        public int $filePermissions = 0o644,
        public int $shardingLevels = 2,
        public int $gcProbability = 1,
        public int $gcDivisor = 1000,
    ) {
        foreach (['directoryPermissions' => $directoryPermissions, 'filePermissions' => $filePermissions] as $option => $mode) {
            if ($mode < 0 || $mode > 0o7777) {
                throw ConfigurationException::invalidOption(option: $option, constraint: 'must be an octal mode between 0 and 07777');
            }
        }

        if ($shardingLevels < 0 || $shardingLevels > 8) {
            throw ConfigurationException::invalidOption(option: 'shardingLevels', constraint: 'must be between 0 and 8');
        }

        if ($gcProbability < 0) {
            throw ConfigurationException::invalidOption(option: 'gcProbability', constraint: 'must be >= 0');
        }

        if ($gcDivisor < 1) {
            throw ConfigurationException::invalidOption(option: 'gcDivisor', constraint: 'must be >= 1');
        }
    }
}
