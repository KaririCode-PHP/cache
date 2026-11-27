<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Registry;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Registry\CacheRegistry;
use KaririCode\Cache\Tests\Fixtures\FlakyAdapter;
use PHPUnit\Framework\TestCase;

/**
 * clearAll() reports a store that failed to clear and still clears the rest.
 */
final class RegistryClearAllTest extends TestCase
{
    public function testAFailedStoreIsReportedAndTheOthersAreStillCleared(): void
    {
        foreach ([true, false] as $failingFirst) {
            $flaky = new FlakyAdapter();
            $flaky->flushSucceeds = false;
            $healthy = new Cache(new ArrayAdapter());
            $healthy->set('k', 1);

            $registry = new CacheRegistry();
            $failingFirst
                ? $registry->register('a', new Cache($flaky))->register('b', $healthy)
                : $registry->register('a', $healthy)->register('b', new Cache($flaky));

            self::assertFalse($registry->clearAll());
            self::assertFalse($healthy->has('k'));
        }

        self::assertTrue((new CacheRegistry())->register('default', new Cache(new ArrayAdapter()))->clearAll());
    }
}
