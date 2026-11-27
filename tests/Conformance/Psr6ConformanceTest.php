<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Conformance;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Bridge\Psr6Bridge;
use KaririCode\Cache\Cache;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;

/**
 * PSR-6 clauses for Psr6Bridge.
 *
 * @see https://www.php-fig.org/psr/psr-6/
 */
#[Group('security')]
final class Psr6ConformanceTest extends TestCase
{
    private ArrayAdapter $adapter;
    private Psr6Bridge $pool;

    protected function setUp(): void
    {
        $this->adapter = new ArrayAdapter();
        $this->pool = new Psr6Bridge(new Cache($this->adapter));
    }

    public function testMissThenSaveThenHit(): void
    {
        $item = $this->pool->getItem('k');
        self::assertFalse($item->isHit());
        self::assertNull($item->get());

        self::assertTrue($this->pool->save($item->set('v')));

        $again = $this->pool->getItem('k');
        self::assertTrue($again->isHit());
        self::assertSame('v', $again->get());
        self::assertTrue($this->pool->hasItem('k'));
    }

    public function testCachedNullIsAHit(): void
    {
        $this->pool->save($this->pool->getItem('n')->set(null));

        self::assertTrue($this->pool->getItem('n')->isHit());
    }

    public function testDeferredItemsAreVisibleBeforeCommit(): void
    {
        $this->pool->saveDeferred($this->pool->getItem('d')->set('deferred'));

        self::assertTrue($this->pool->hasItem('d'));
        self::assertTrue($this->pool->getItem('d')->isHit());
        self::assertSame('deferred', $this->pool->getItem('d')->get());
        self::assertNull($this->adapter->read('d'), 'not persisted before commit');

        self::assertTrue($this->pool->commit());
        self::assertNotNull($this->adapter->read('d'));
    }

    public function testDeferredItemsAreCommittedOnDestruction(): void
    {
        $pool = new Psr6Bridge(new Cache($this->adapter));
        $pool->saveDeferred($pool->getItem('late')->set('v'));

        unset($pool);

        self::assertSame('v', (new Psr6Bridge(new Cache($this->adapter)))->getItem('late')->get());
    }

    public function testExpirationInThePastOrZeroDeletes(): void
    {
        $this->pool->save($this->pool->getItem('a')->set(1));
        $this->pool->save($this->pool->getItem('a')->set(1)->expiresAfter(0));
        self::assertFalse($this->pool->hasItem('a'));

        $this->pool->save($this->pool->getItem('b')->set(1)->expiresAt(new \DateTimeImmutable('-1 minute')));
        self::assertFalse($this->pool->hasItem('b'));
    }

    public function testExpiresAfterIsHonoured(): void
    {
        $this->pool->save($this->pool->getItem('t')->set(1)->expiresAfter(1));

        self::assertTrue($this->pool->getItem('t')->isHit());
        usleep(2_100_000);
        self::assertFalse($this->pool->getItem('t')->isHit());
    }

    public function testBatchAndClear(): void
    {
        $this->pool->save($this->pool->getItem('a')->set(1));

        $items = $this->pool->getItems(['a', 'b']);
        self::assertSame(['a', 'b'], array_keys(is_array($items) ? $items : iterator_to_array($items)));
        self::assertTrue($this->pool->deleteItems(['a', 'missing']));
        self::assertTrue($this->pool->deleteItem('missing'), 'deleting a missing item is not an error');

        $this->pool->saveDeferred($this->pool->getItem('d')->set(1));
        self::assertTrue($this->pool->clear());
        self::assertFalse($this->pool->hasItem('d'), 'clear() drops deferred items');
    }

    public function testIllegalKeysRaiseThePsrInvalidArgumentException(): void
    {
        foreach (['', 'a:b', 'a{b', 'a@b'] as $key) {
            try {
                $this->pool->getItem($key);
                self::fail("key '{$key}' accepted");
            } catch (InvalidArgumentException $e) {
                self::assertInstanceOf(CacheException::class, $e);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->pool->getItems(['ok', 'bad:key']);
    }
}
