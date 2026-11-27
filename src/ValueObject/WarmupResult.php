<?php

declare(strict_types=1);

namespace KaririCode\Cache\ValueObject;

/**
 * Outcome of one cache warmer run, returned by CacheWarmerRunner.
 *
 * Replaces the 4.0 associative-array report: state is exposed through
 * public readonly properties instead of array keys.
 *
 * ARFA 1.3 P5 (Continuous Observability): per-warmer timing and error.
 * Spec V4: DPO (final readonly class).
 *
 * Preconditions: none.
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     foreach ($runner->warmAll() as $result) { $log->info($result->name, ['ms' => $result->elapsedMs]); }
 *
 * References: SPEC-001 §8.
 *
 * @see https://www.php.net/manual/en/function.hrtime.php
 */
final readonly class WarmupResult
{
    public function __construct(
        public string $name,
        public float $elapsedMs,
        public bool $skipped,
        public ?\Throwable $error = null,
    ) {}
}
