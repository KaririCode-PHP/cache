<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

use KaririCode\Contract\Exception\Configuration;

/**
 * An impossible configuration, found at construction or call time: an
 * option outside its range, a stale window shorter than the fresh window,
 * an empty scope, or a required PHP extension that is not loaded.
 *
 * Messages name the option and its constraint, never the rejected value
 * (E-047).
 *
 * ARFA 1.3 P5 (Continuous Observability): codes 8640-8641.
 * Spec V4: exception, family Configuration (§8.4); fail fast.
 *
 * Preconditions: none.
 * Thread-safety: stateless; the validate*() guards are pure.
 *
 * Side effects: none.
 *
 * Example:
 *     ConfigurationException::validateStaleWindow(freshTtl: 60, staleTtl: 600);
 *
 * References: ADR-010; SPEC-001 §11.
 *
 * @see https://martinfowler.com/ieeeSoftware/failFast.pdf
 */
final class ConfigurationException extends \LogicException implements CacheException, Configuration
{
    public const int INVALID_OPTION = 8640;
    public const int EXTENSION_MISSING = 8641;

    public static function invalidOption(string $option, string $constraint): self
    {
        return new self(message: "Invalid cache option {$option}: {$constraint}.", code: self::INVALID_OPTION);
    }

    public static function extensionMissing(string $extension): self
    {
        return new self(message: "The PHP extension {$extension} is required but not loaded or not enabled.", code: self::EXTENSION_MISSING);
    }

    /**
     * Throw unless 1 <= $freshTtl <= $staleTtl (rememberStale window).
     */
    public static function validateStaleWindow(int $freshTtl, int $staleTtl): void
    {
        if ($freshTtl < 1) {
            throw self::invalidOption(option: 'freshTtl', constraint: 'must be at least 1 second');
        }

        if ($staleTtl < $freshTtl) {
            throw self::invalidOption(option: 'staleTtl', constraint: 'must not be shorter than freshTtl');
        }
    }
}
