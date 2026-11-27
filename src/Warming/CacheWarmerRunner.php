<?php

declare(strict_types=1);

namespace KaririCode\Cache\Warming;

use KaririCode\Cache\Contract\Cache;
use KaririCode\Cache\Contract\CacheWarmer;
use KaririCode\Cache\ValueObject\WarmupResult;

/**
 * Runs registered cache warmers in order and reports each outcome.
 *
 * A failing warmer does not stop the others: its throwable is recorded in
 * the WarmupResult, and the caller decides whether a failed warm-up should
 * abort a deploy.
 *
 * ARFA 1.3 P5 (Continuous Observability): timing and error per warmer.
 * Spec V4: Entity (mutable warmer list).
 *
 * Preconditions: warmers are idempotent.
 * Thread-safety: mutable warmer list: configure at boot.
 *
 * Side effects: runs every warmer against the cache; failures are recorded, not thrown.
 *
 * Example:
 *     $results = (new CacheWarmerRunner($cache, [new CatalogWarmer()]))->warmAll(skipOptional: true);
 *
 * References: SPEC-001 §8.
 *
 * @see https://symfony.com/doc/current/reference/dic_tags.html#kernel-cache-warmer
 */
final class CacheWarmerRunner
{
    /** @var list<CacheWarmer> */
    private array $warmers;

    /**
     * @param list<CacheWarmer> $warmers
     */
    public function __construct(
        private readonly Cache $cache,
        array $warmers = [],
    ) {
        $this->warmers = $warmers;
    }

    public function add(CacheWarmer $warmer): void
    {
        $this->warmers[] = $warmer;
    }

    /**
     * @return list<WarmupResult>
     */
    public function warmAll(bool $skipOptional = false): array
    {
        $results = [];

        foreach ($this->warmers as $warmer) {
            if ($skipOptional && $warmer->isOptional()) {
                $results[] = new WarmupResult($warmer->name(), 0.0, skipped: true);

                continue;
            }

            $start = hrtime(true);
            $error = null;

            try {
                $warmer->warm($this->cache);
            } catch (\Throwable $e) {
                $error = $e;
            }

            $results[] = new WarmupResult(name: $warmer->name(), elapsedMs: (float) (hrtime(true) - $start) / 1e6, skipped: false, error: $error);
        }

        return $results;
    }
}
