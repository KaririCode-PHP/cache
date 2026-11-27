<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Enum;

use KaririCode\Cache\Contract\EvictionPolicy;
use KaririCode\Cache\Enum\EvictionStrategy;
use KaririCode\Cache\Eviction\FifoPolicy;
use KaririCode\Cache\Eviction\LfuPolicy;
use KaririCode\Cache\Eviction\LruPolicy;
use KaririCode\Cache\Eviction\NullEvictionPolicy;
use KaririCode\Cache\Eviction\SievePolicy;
use KaririCode\Cache\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvictionStrategyTest extends TestCase
{
    /**
     * @return iterable<string, array{EvictionStrategy, class-string<EvictionPolicy>}>
     */
    public static function strategies(): iterable
    {
        yield 'sieve' => [EvictionStrategy::Sieve, SievePolicy::class];
        yield 'lru' => [EvictionStrategy::Lru, LruPolicy::class];
        yield 'lfu' => [EvictionStrategy::Lfu, LfuPolicy::class];
        yield 'fifo' => [EvictionStrategy::Fifo, FifoPolicy::class];
    }

    /**
     * @param class-string<EvictionPolicy> $class
     */
    #[DataProvider('strategies')]
    public function testCreatePolicyBuildsAFreshPolicyWithTheCapacity(EvictionStrategy $strategy, string $class): void
    {
        $policy = $strategy->createPolicy(capacity: 42);

        self::assertInstanceOf($class, $policy);
        self::assertSame(42, $policy->capacity);
        self::assertSame(0, $policy->count());
        self::assertNotSame($policy, $strategy->createPolicy(capacity: 42));
    }

    public function testDefaultCapacityIsTenThousand(): void
    {
        self::assertSame(10_000, EvictionStrategy::Sieve->createPolicy()->capacity);
        self::assertSame(10_000, (new LruPolicy())->capacity);
    }

    public function testNoneIsUnboundedWhateverTheCapacity(): void
    {
        $policy = EvictionStrategy::None->createPolicy(capacity: 5);

        self::assertInstanceOf(NullEvictionPolicy::class, $policy);
        self::assertSame(0, $policy->capacity);
    }

    /**
     * @param class-string<EvictionPolicy> $class
     */
    #[DataProvider('strategies')]
    public function testNegativeCapacityIsRejected(EvictionStrategy $strategy, string $class): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(ConfigurationException::INVALID_OPTION);

        new $class(-1);
    }
}
