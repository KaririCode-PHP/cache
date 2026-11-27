<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Registry;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Exception\StoreNotFoundException;
use KaririCode\Cache\Registry\CacheRegistry;
use PHPUnit\Framework\TestCase;

final class CacheRegistryTest extends TestCase
{
    public function testStoreWithoutNameReturnsTheDefaultStore(): void
    {
        $main = new Cache(new ArrayAdapter());
        $registry = (new CacheRegistry(defaultStore: 'main'))->register('main', $main);

        $registry->store()->set('key', 'value');

        self::assertSame($main, $registry->store());
        self::assertSame('value', $main->get('key'));
    }

    public function testNamedStoresAreIsolated(): void
    {
        $registry = (new CacheRegistry())
            ->register('redis', new Cache(new ArrayAdapter()))
            ->register('file', new Cache(new ArrayAdapter()));

        $registry->store('redis')->set('key', 'redis_value');
        $registry->store('file')->set('key', 'file_value');

        self::assertSame('redis_value', $registry->store('redis')->get('key'));
        self::assertSame('file_value', $registry->store('file')->get('key'));
    }

    public function testUnknownStoreHasAStableCode(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionCode(StoreNotFoundException::STORE_NOT_REGISTERED);

        (new CacheRegistry())->register('a', new Cache(new ArrayAdapter()))->store('missing');
    }

    public function testUnregisteredDefaultHasAStableCode(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionCode(StoreNotFoundException::STORE_NOT_REGISTERED);

        (new CacheRegistry(defaultStore: 'missing'))->store();
    }

    public function testHasAndNames(): void
    {
        $registry = (new CacheRegistry())
            ->register('a', new Cache(new ArrayAdapter()))
            ->register('1', new Cache(new ArrayAdapter()));

        self::assertTrue($registry->has('a'));
        self::assertFalse($registry->has('b'));
        // A numeric name comes back as a string, not as PHP's int array key.
        self::assertSame(['a', '1'], $registry->names());
    }

    public function testClearAllClearsEveryStore(): void
    {
        $a = new Cache(new ArrayAdapter());
        $b = new Cache(new ArrayAdapter());
        $a->set('key', 'a');
        $b->set('key', 'b');

        self::assertTrue((new CacheRegistry())->register('a', $a)->register('b', $b)->clearAll());
        self::assertNull($a->get('key'));
        self::assertNull($b->get('key'));
    }

    public function testEmptyNamesAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        (new CacheRegistry())->register('', new Cache(new ArrayAdapter()));
    }
}
