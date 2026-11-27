<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Stampede;

use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Stampede\LockingPrevention;
use KaririCode\Cache\Stampede\XFetchPrevention;
use PHPUnit\Framework\TestCase;

final class LockingPreventionTest extends TestCase
{
    public function testFreshEntriesAreNeverRecomputed(): void
    {
        $locking = new LockingPrevention();

        self::assertFalse($locking->shouldRecompute('k', 100.0, 200.0, 1.0));
    }

    public function testOneCallerPerKeyAfterExpiry(): void
    {
        $locking = new LockingPrevention(lockDuration: 10.0);

        self::assertTrue($locking->shouldRecompute('k', 300.0, 200.0, 1.0));
        self::assertFalse($locking->shouldRecompute('k', 301.0, 200.0, 1.0));
        self::assertTrue($locking->shouldRecompute('other', 301.0, 200.0, 1.0), 'locks are per key');
        self::assertTrue($locking->shouldRecompute('k', 311.0, 200.0, 1.0), 'lock expired');
    }

    public function testReleaseLock(): void
    {
        $locking = new LockingPrevention();
        self::assertTrue($locking->tryLock('k', 1.0));
        self::assertFalse($locking->tryLock('k', 2.0));

        $locking->releaseLock('k');

        self::assertTrue($locking->tryLock('k', 3.0));
    }

    public function testInvalidLockDurationIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new LockingPrevention(0.0);
    }

    public function testInvalidXFetchBetaIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new XFetchPrevention(beta: -1.0);
    }
}
