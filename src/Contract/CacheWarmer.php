<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Pre-populates a cache before traffic arrives (deploy, worker boot).
 *
 * Warmers marked optional may be skipped by CacheWarmerRunner, for
 * example in development.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): warmers only see the Cache contract.
 * Spec V4: contract (strategy).
 *
 * Preconditions: warm() is idempotent: it may run on every deploy.
 * Thread-safety: implementation-defined.
 *
 * Side effects: writes to the cache it receives.
 *
 * Example:
 *     (new CacheWarmerRunner($cache, [new CatalogWarmer()]))->warmAll();
 *
 * References: SPEC-001 §8.
 *
 * @see https://symfony.com/doc/current/reference/dic_tags.html#kernel-cache-warmer
 */
interface CacheWarmer
{
    public function warm(Cache $cache): void;

    public function isOptional(): bool;

    public function name(): string;
}
