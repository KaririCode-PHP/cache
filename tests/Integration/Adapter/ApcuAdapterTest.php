<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Integration\Adapter;

use KaririCode\Cache\Adapter\ApcuAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Needs APCu enabled for the CLI (composer test passes -d apc.enable_cli=1).
 */
final class ApcuAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            self::markTestSkipped('APCu not enabled (run with -d apc.enable_cli=1)');
        }

        apcu_clear_cache();
    }

    public function testReadWriteRemove(): void
    {
        $adapter = new ApcuAdapter('t1:');

        self::assertTrue($adapter->write('k', ['v' => 1]));
        self::assertSame(['v' => 1], $adapter->read('k'));
        self::assertTrue($adapter->remove('k'));
        self::assertTrue($adapter->remove('k'), 'idempotent');
        self::assertNull($adapter->read('k'));
    }

    public function testZeroTtlDeletesInsteadOfStoringForever(): void
    {
        $adapter = new ApcuAdapter('t2:');
        $adapter->write('k', 'v');

        self::assertTrue($adapter->write('k', 'v', 0));
        self::assertFalse($adapter->exists('k'));
    }

    public function testFlushWithPrefixKeepsOtherPrefixes(): void
    {
        $mine = new ApcuAdapter('mine:');
        $other = new ApcuAdapter('other:');
        $mine->write('a', 1);
        $other->write('a', 2);

        $mine->flush();

        self::assertNull($mine->read('a'));
        self::assertSame(2, $other->read('a'));
    }

    public function testBatchAndStats(): void
    {
        $adapter = new ApcuAdapter('t3:');

        self::assertTrue($adapter->writeMultiple(['a' => 1, 'b' => 2]));
        self::assertSame(['a' => 1, 'b' => 2, 'c' => null], $adapter->readMultiple(['a', 'b', 'c']));
        self::assertSame(2, $adapter->stats()->hits);
        self::assertSame(1, $adapter->stats()->misses);
    }
}
