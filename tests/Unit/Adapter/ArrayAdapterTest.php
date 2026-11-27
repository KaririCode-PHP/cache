<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Adapter;

use KaririCode\Cache\Adapter\ArrayAdapter;
use PHPUnit\Framework\TestCase;

final class ArrayAdapterTest extends TestCase
{
    private ArrayAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new ArrayAdapter(maxItems: 5);
    }

    public function testWriteAndRead(): void
    {
        $this->adapter->write('key1', 'value1');

        self::assertSame('value1', $this->adapter->read('key1'));
    }

    public function testReadMissReturnsNull(): void
    {
        self::assertNull($this->adapter->read('nonexistent'));
    }

    public function testWriteWithTtlExpiresEntry(): void
    {
        $this->adapter->write('key1', 'value1', 1);

        self::assertSame('value1', $this->adapter->read('key1'));

        usleep(1_100_000); // 1.1 seconds

        self::assertNull($this->adapter->read('key1'));
    }

    public function testRemoveDeletesEntry(): void
    {
        $this->adapter->write('key1', 'value1');

        self::assertTrue($this->adapter->remove('key1'));
        self::assertNull($this->adapter->read('key1'));
    }

    public function testRemoveNonexistentIsIdempotent(): void
    {
        self::assertTrue($this->adapter->remove('nonexistent'), 'remove() is idempotent (PSR-16 delete semantics)');
    }

    public function testExistsReturnsCorrectState(): void
    {
        $this->adapter->write('key1', 'value1');

        self::assertTrue($this->adapter->exists('key1'));
        self::assertFalse($this->adapter->exists('nonexistent'));
    }

    public function testExistsReturnsFalseForExpiredEntry(): void
    {
        $this->adapter->write('key1', 'value1', 1);

        usleep(1_100_000);

        self::assertFalse($this->adapter->exists('key1'));
    }

    public function testFlushClearsAllEntries(): void
    {
        $this->adapter->write('key1', 'value1');
        $this->adapter->write('key2', 'value2');
        $this->adapter->flush();

        self::assertNull($this->adapter->read('key1'));
        self::assertNull($this->adapter->read('key2'));
    }

    public function testFifoOverflowWhenAtCapacity(): void
    {
        // Fill to capacity (5)
        for ($i = 1; $i <= 5; ++$i) {
            $this->adapter->write("key{$i}", "value{$i}");
        }

        // Write 6th entry — triggers FIFO overflow (drops oldest = key1)
        $this->adapter->write('key6', 'value6');

        self::assertSame('value6', $this->adapter->read('key6'));
        self::assertNull($this->adapter->read('key1'));
    }

    public function testUpdateExistingKeyDoesNotTriggerOverflow(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->adapter->write("key{$i}", "value{$i}");
        }

        // Update existing key — should NOT trigger overflow
        $this->adapter->write('key3', 'updated');

        self::assertSame('updated', $this->adapter->read('key3'));
        // All other keys should still be present
        self::assertSame('value1', $this->adapter->read('key1'));
        self::assertSame('value5', $this->adapter->read('key5'));
    }

    public function testReadMultipleReturnsMixedResults(): void
    {
        $this->adapter->write('a', 1);
        $this->adapter->write('b', 2);

        $results = $this->adapter->readMultiple(['a', 'b', 'c']);

        self::assertSame(1, $results['a']);
        self::assertSame(2, $results['b']);
        self::assertNull($results['c']);
    }

    public function testWriteMultipleStoresAllEntries(): void
    {
        $this->adapter->writeMultiple(['x' => 10, 'y' => 20], 60);

        self::assertSame(10, $this->adapter->read('x'));
        self::assertSame(20, $this->adapter->read('y'));
    }

    public function testStatsReflectOperations(): void
    {
        $this->adapter->write('a', 1);
        $this->adapter->read('a'); // hit
        $this->adapter->read('b'); // miss

        $stats = $this->adapter->stats();

        self::assertSame(1, $stats->hits);
        self::assertSame(1, $stats->misses);
        self::assertSame(1, $stats->writes);
        self::assertSame('array', $stats->adapterName);
    }

    public function testPruneRemovesExpiredEntries(): void
    {
        $this->adapter->write('fresh', 'value', 3600);
        $this->adapter->write('expired', 'value', 1);

        usleep(1_100_000);

        $pruned = $this->adapter->prune();

        self::assertSame(1, $pruned);
        self::assertNull($this->adapter->read('expired'));
        self::assertSame('value', $this->adapter->read('fresh'));
    }

    public function testNameReturnsArray(): void
    {
        self::assertSame('array', $this->adapter->name());
    }

    public function testIsAvailableAlwaysTrue(): void
    {
        self::assertTrue($this->adapter->isAvailable());
    }
}
