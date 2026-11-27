<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Warming;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Contract\CacheWarmer;
use KaririCode\Cache\Warming\CacheWarmerRunner;
use PHPUnit\Framework\TestCase;

/**
 * Optional warmers run unless skipped, skipped ones report no time, and a
 * failing warmer is reported without stopping the others.
 */
final class WarmerResultsTest extends TestCase
{
    private function warmer(string $name, bool $optional, ?\Throwable $failure = null): CacheWarmer
    {
        return new class ($name, $optional, $failure) implements CacheWarmer {
            public function __construct(private string $name, private bool $optional, private ?\Throwable $failure) {}

            public function warm(CacheContract $cache): void
            {
                if (null !== $this->failure) {
                    throw $this->failure;
                }

                $cache->set($this->name, true);
            }

            public function isOptional(): bool
            {
                return $this->optional;
            }

            public function name(): string
            {
                return $this->name;
            }
        };
    }

    public function testOptionalWarmersRunUnlessSkipped(): void
    {
        $cache = new Cache(new ArrayAdapter());
        $runner = new CacheWarmerRunner($cache, [$this->warmer('required', false), $this->warmer('optional', true)]);

        $all = $runner->warmAll();
        self::assertSame([false, false], [$all[0]->skipped, $all[1]->skipped]);
        self::assertTrue($cache->has('optional'));

        foreach ($all as $result) {
            self::assertGreaterThanOrEqual(0.0, $result->elapsedMs);
            self::assertLessThan(60_000.0, $result->elapsedMs);
        }

        $skipping = $runner->warmAll(skipOptional: true);
        self::assertFalse($skipping[0]->skipped);
        self::assertTrue($skipping[1]->skipped);
        self::assertSame(0.0, $skipping[1]->elapsedMs);
    }

    public function testAFailingWarmerIsReportedAndTheOthersStillRun(): void
    {
        $failure = new \RuntimeException('boom');
        $cache = new Cache(new ArrayAdapter());

        $results = (new CacheWarmerRunner($cache, [$this->warmer('bad', false, $failure), $this->warmer('good', false)]))->warmAll();

        self::assertSame($failure, $results[0]->error);
        self::assertNull($results[1]->error);
        self::assertTrue($cache->has('good'));
    }
}
