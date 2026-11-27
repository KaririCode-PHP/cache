<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Exception\InvalidKeyException;
use KaririCode\Cache\Layered\LayeredCache;
use KaririCode\Cache\Tag\TagAwareCache;
use KaririCode\Cache\Tests\Fixtures\FlakyAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every implementation of the Cache contract shares: key
 * validation on every keyed operation, the TTL rules of ADR-010, defaults,
 * and honest results for batch operations when the backend refuses part
 * of the work.
 */
final class CacheContractTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): CacheContract}>
     */
    public static function caches(): iterable
    {
        yield 'Cache' => [static fn(): CacheContract => new Cache(new ArrayAdapter())];
        yield 'TagAwareCache' => [static fn(): CacheContract => new TagAwareCache(new ArrayAdapter())];
        yield 'LayeredCache' => [static fn(): CacheContract => (new LayeredCache())->addLayer(new ArrayAdapter())->addLayer(new ArrayAdapter())];
    }

    /**
     * @return iterable<string, array{\Closure(): CacheContract, string, \Closure(CacheContract): mixed}>
     */
    public static function keyedOperations(): iterable
    {
        $operations = [
            'get' => static fn(CacheContract $c): mixed => $c->get('bad{key'),
            'set' => static fn(CacheContract $c): mixed => $c->set('bad{key', 1),
            'delete' => static fn(CacheContract $c): mixed => $c->delete('bad{key'),
            'has' => static fn(CacheContract $c): mixed => $c->has('bad{key'),
            'remember' => static fn(CacheContract $c): mixed => $c->remember('bad{key', static fn(): int => 1, 60),
            'rememberStale' => static fn(CacheContract $c): mixed => $c->rememberStale('bad{key', static fn(): int => 1, 10, 60),
            'getMultiple' => static fn(CacheContract $c): mixed => iterator_to_array((static fn(iterable $i): \Generator => yield from $i)($c->getMultiple(['ok', 'bad{key']))),
            'setMultiple' => static fn(CacheContract $c): mixed => $c->setMultiple(['ok' => 1, 'bad{key' => 2]),
            'deleteMultiple' => static fn(CacheContract $c): mixed => $c->deleteMultiple(['ok', 'bad{key']),
        ];

        foreach (self::caches() as $cacheName => [$factory]) {
            foreach ($operations as $operationName => $operation) {
                yield "{$cacheName}::{$operationName}" => [$factory, $operationName, $operation];
            }
        }
    }

    /**
     * @param \Closure(): CacheContract $factory
     * @param \Closure(CacheContract): mixed $operation
     */
    #[DataProvider('keyedOperations')]
    #[Group('security')]
    public function testEveryKeyedOperationValidatesTheKey(\Closure $factory, string $operationName, \Closure $operation): void
    {
        $this->expectException(InvalidKeyException::class);

        $operation($factory());
    }

    /**
     * @param \Closure(): CacheContract $factory
     */
    #[DataProvider('caches')]
    public function testZeroAndNegativeTtlDeleteTheEntryAndSucceed(\Closure $factory): void
    {
        $cache = $factory();

        foreach ([0, -5] as $ttl) {
            $cache->set('k', 'old');
            self::assertTrue($cache->set('k', 'new', $ttl), "set ttl {$ttl}");
            self::assertFalse($cache->has('k'), "set ttl {$ttl} must delete");
        }

        $cache->set('m', 'old');
        self::assertTrue($cache->setMultiple(['m' => 'new'], 0));
        self::assertFalse($cache->has('m'));
    }

    /**
     * @param \Closure(): CacheContract $factory
     */
    #[DataProvider('caches')]
    public function testRememberWithZeroTtlComputesWithoutStoring(\Closure $factory): void
    {
        $cache = $factory();
        $cache->set('k', 'old');

        self::assertSame('fresh', $cache->remember('k', static fn(): string => 'fresh', 0));
        self::assertFalse($cache->has('k'));
        self::assertSame('again', $cache->remember('k', static fn(): string => 'again', 0));
    }

    /**
     * @param \Closure(): CacheContract $factory
     */
    #[DataProvider('caches')]
    public function testDefaultsApplyOnlyToMisses(\Closure $factory): void
    {
        $cache = $factory();
        $cache->set('k', 'stored');

        self::assertSame('stored', $cache->get('k', 'default'));
        self::assertSame('default', $cache->get('missing', 'default'));
    }

    /**
     * @return iterable<string, array{\Closure(FlakyAdapter): CacheContract}>
     */
    public static function cachesOverAFlakyBackend(): iterable
    {
        yield 'Cache' => [static fn(FlakyAdapter $a): CacheContract => new Cache($a)];
        yield 'TagAwareCache' => [static fn(FlakyAdapter $a): CacheContract => new TagAwareCache($a)];
        yield 'LayeredCache' => [static fn(FlakyAdapter $a): CacheContract => (new LayeredCache())->addLayer($a)];
    }

    /**
     * @param \Closure(FlakyAdapter): CacheContract $factory
     */
    #[DataProvider('cachesOverAFlakyBackend')]
    public function testBatchOperationsReportAPartialFailureAndStillTryEveryKey(\Closure $factory): void
    {
        $cache = $factory(new FlakyAdapter());

        self::assertFalse($cache->setMultiple(['fail-1' => 1, 'ok-1' => 2]));
        self::assertSame(2, $cache->get('ok-1'));
        self::assertFalse($cache->setMultiple(['ok-2' => 3, 'fail-2' => 4]));
        self::assertSame(3, $cache->get('ok-2'));
        self::assertTrue($cache->setMultiple(['ok-3' => 5]));

        self::assertFalse($cache->deleteMultiple(['fail-1', 'ok-1']));
        self::assertFalse($cache->has('ok-1'));
        self::assertFalse($cache->deleteMultiple(['ok-2', 'fail-2']));
        self::assertFalse($cache->has('ok-2'));
        self::assertTrue($cache->deleteMultiple(['ok-3']));
    }

    public function testTagInvalidationReportsAFailedVersionWrite(): void
    {
        $cache = new TagAwareCache(new FlakyAdapter());

        self::assertFalse($cache->invalidateTags(['fail-tag', 'ok-tag']));
        self::assertFalse($cache->invalidateTags(['ok-tag', 'fail-tag']));
        self::assertTrue($cache->invalidateTags(['ok-tag', 'other-tag']));
    }

    #[Group('security')]
    public function testTagsFollowTheKeyRules(): void
    {
        $cache = new TagAwareCache(new ArrayAdapter());

        foreach ([static fn() => $cache->setTagged('k', 1, ['bad{tag']), static fn() => $cache->invalidateTags(['bad{tag']), static fn() => $cache->remember('k', static fn(): int => 1, 60, ['bad{tag'])] as $operation) {
            try {
                $operation();
                self::fail('an invalid tag must be rejected');
            } catch (InvalidKeyException $e) {
                self::assertSame(InvalidKeyException::RESERVED_CHARACTERS, $e->getCode());
            }
        }
    }

    public function testLayersAreAllWrittenRemovedAndFlushedEvenWhenOneFails(): void
    {
        foreach ([true, false] as $flakyFirst) {
            $reliable = new ArrayAdapter();
            $flaky = new FlakyAdapter();
            $cache = new LayeredCache();
            $flakyFirst ? $cache->addLayer($flaky)->addLayer($reliable) : $cache->addLayer($reliable)->addLayer($flaky);

            self::assertFalse($cache->set('fail-k', 1), 'a refused layer write fails the set');
            self::assertTrue($reliable->exists('fail-k'), 'the other layer is still written');

            self::assertFalse($cache->delete('fail-k'), 'a refused layer remove fails the delete');
            self::assertFalse($reliable->exists('fail-k'), 'the other layer is still cleaned');

            $cache->set('ok', 1);
            $flaky->flushSucceeds = false;
            self::assertFalse($cache->clear(), 'a refused layer flush fails the clear');
            self::assertFalse($reliable->exists('ok'), 'the other layer is still flushed');
            $flaky->flushSucceeds = true;
            self::assertTrue($cache->clear());
        }
    }
}
