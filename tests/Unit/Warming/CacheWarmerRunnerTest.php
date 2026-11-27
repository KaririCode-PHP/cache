<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Warming;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Contract\CacheWarmer;
use KaririCode\Cache\Warming\CacheWarmerRunner;
use PHPUnit\Framework\TestCase;

final class CacheWarmerRunnerTest extends TestCase
{
    public function testReportsSuccessSkipAndFailureWithoutStopping(): void
    {
        $cache = new Cache(new ArrayAdapter());
        $runner = new CacheWarmerRunner($cache, [
            self::warmer('config', false, static fn(CacheContract $c) => $c->set('config', ['debug' => false])),
            self::warmer('optional', true, static fn(CacheContract $c) => $c->set('optional', 1)),
        ]);
        $runner->add(self::warmer('broken', false, static function (): void {
            throw new \LogicException('boom');
        }));

        $results = $runner->warmAll(skipOptional: true);

        self::assertCount(3, $results);
        self::assertSame(['config', 'optional', 'broken'], array_map(static fn($r): string => $r->name, $results));
        self::assertNull($results[0]->error);
        self::assertTrue($results[1]->skipped);
        self::assertInstanceOf(\LogicException::class, $results[2]->error);
        self::assertSame(['debug' => false], $cache->get('config'));
        self::assertFalse($cache->has('optional'));
        self::assertGreaterThanOrEqual(0.0, $results[0]->elapsedMs);
    }

    /**
     * @param \Closure(CacheContract): mixed $warm
     */
    private static function warmer(string $name, bool $optional, \Closure $warm): CacheWarmer
    {
        return new class ($name, $optional, $warm) implements CacheWarmer {
            /**
             * @param \Closure(CacheContract): mixed $warm
             */
            public function __construct(private string $name, private bool $optional, private \Closure $warm) {}

            public function warm(CacheContract $cache): void
            {
                ($this->warm)($cache);
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
}
