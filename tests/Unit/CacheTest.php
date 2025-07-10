<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit;

use KaririCode\Cache\Cache;
use KaririCode\Cache\Contract\CacheEventListener;
use KaririCode\Cache\Contract\CacheKeyValidator;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Contract\MetricsCollector;
use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Contract\StampedeProtector;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Item\AdvancedCacheProxy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \KaririCode\Cache\Cache
 */
final class CacheTest extends TestCase
{
    private Cache $cache;
    private LayeredCache|MockObject $layeredCache;
    private Serializer|MockObject $serializer;
    private StampedeProtector|MockObject $stampedeProtector;
    private MetricsCollector|MockObject $metricsCollector;
    private LoggerInterface|MockObject $logger;
    private CacheEventListener|MockObject $eventListener;
    private CacheKeyValidator|MockObject $keyValidator;
    private EventDispatcherInterface|MockObject $eventDispatcher;

    protected function setUp(): void
    {
        $this->layeredCache = $this->createMock(LayeredCache::class);
        $this->serializer = $this->createMock(Serializer::class);
        $this->stampedeProtector = $this->createMock(StampedeProtector::class);
        $this->metricsCollector = $this->createMock(MetricsCollector::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventListener = $this->createMock(CacheEventListener::class);
        $this->keyValidator = $this->createMock(CacheKeyValidator::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->cache = new Cache(
            $this->layeredCache,
            $this->serializer,
            $this->stampedeProtector,
            $this->metricsCollector,
            $this->logger,
            $this->eventListener,
            $this->keyValidator,
            $this->eventDispatcher
        );
    }

    public function testGetSuccessfullyReturnsHitValue(): void
    {
        // Arrange
        $key = 'test-key';
        $serializedValue = 'serialized-value';
        $unserializedValue = 'test-value';
        $cacheItem = $this->createMockCacheItem($key, $serializedValue, true);

        $this->keyValidator
            ->expects($this->once())
            ->method('validateKey')
            ->with($key)
            ->willReturn(true);

        $this->layeredCache
            ->expects($this->once())
            ->method('getItem')
            ->with($key)
            ->willReturn($cacheItem);

        $this->serializer
            ->expects($this->once())
            ->method('unserialize')
            ->with($serializedValue)
            ->willReturn($unserializedValue);

        $this->metricsCollector
            ->expects($this->once())
            ->method('recordHit')
            ->with($key, $this->isType('float'));

        // Act
        $result = $this->cache->get($key);

        // Assert
        $this->assertSame($unserializedValue, $result);
    }

    public function testGetReturnsDefaultValueOnMiss(): void
    {
        // Arrange
        $key = 'test-key';
        $defaultValue = 'default-value';
        $cacheItem = $this->createMockCacheItem($key, null, false);

        $this->keyValidator
            ->expects($this->once())
            ->method('validateKey')
            ->with($key)
            ->willReturn(true);

        $this->layeredCache
            ->expects($this->once())
            ->method('getItem')
            ->with($key)
            ->willReturn($cacheItem);

        $this->metricsCollector
            ->expects($this->once())
            ->method('recordMiss')
            ->with($key);

        // Act
        $result = $this->cache->get($key, $defaultValue);

        // Assert
        $this->assertSame($defaultValue, $result);
    }

    public function testGetThrowsExceptionForInvalidKey(): void
    {
        // Arrange
        $key = 'invalid-key';

        $this->keyValidator
            ->expects($this->once())
            ->method('validateKey')
            ->with($key)
            ->willReturn(false);

        $this->keyValidator
            ->expects($this->once())
            ->method('getValidationErrors')
            ->with($key)
            ->willReturn(['Key contains invalid characters']);

        // Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        $this->cache->get($key);
    }

    public function testSetSuccessfullyStoresValue(): void
    {
        // Arrange
        $key = 'test-key';
        $value = 'test-value';
        $ttl = 3600;
        $serializedValue = 'serialized-value';

        $this->keyValidator
            ->expects($this->once())
            ->method('validateKey')
            ->with($key)
            ->willReturn(true);

        $this->serializer
            ->expects($this->once())
            ->method('serialize')
            ->with($value)
            ->willReturn($serializedValue);

        $this->layeredCache
            ->expects($this->once())
            ->method('save')
            ->willReturn(true);

        // Act
        $result = $this->cache->set($key, $value, $ttl);

        // Assert
        $this->assertTrue($result);
    }

    public function testGetWithCallbackReturnsHitValue(): void
    {
        // Arrange
        $key = 'test-key';
        $cachedValue = 'cached-value';
        $cacheItem = $this->createMockCacheItem($key, 'serialized-value', true);
        $callback = fn () => 'callback-value';

        $this->keyValidator
            ->expects($this->once())
            ->method('validateKey')
            ->with($key)
            ->willReturn(true);

        $this->layeredCache
            ->expects($this->once())
            ->method('getItem')
            ->with($key)
            ->willReturn($cacheItem);

        $this->serializer
            ->expects($this->once())
            ->method('unserialize')
            ->with('serialized-value')
            ->willReturn($cachedValue);

        // Act
        $result = $this->cache->getWithCallback($key, $callback);

        // Assert
        $this->assertSame($cachedValue, $result);
    }

    public function testGetWithCallbackExecutesCallbackOnMiss(): void
    {
        // Arrange
        $key = 'test-key';
        $callbackValue = 'callback-value';
        $cacheItem = $this->createMockCacheItem($key, null, false);
        $callback = fn () => $callbackValue;

        $this->keyValidator
            ->expects($this->exactly(2))
            ->method('validateKey')
            ->with($key)
            ->willReturn(true);

        $this->layeredCache
            ->expects($this->once())
            ->method('getItem')
            ->with($key)
            ->willReturn($cacheItem);

        $this->stampedeProtector
            ->expects($this->once())
            ->method('get')
            ->with($key, $this->isInstanceOf('Closure'))
            ->willReturnCallback(fn ($key, $cb) => $cb());

        // Act
        $result = $this->cache->getWithCallback($key, $callback);

        // Assert
        $this->assertSame($callbackValue, $result);
    }

    public function testDeleteSuccessfullyRemovesItem(): void
    {
        // Arrange
        $key = 'test-key';

        $this->keyValidator
            ->expects($this->once())
            ->method('validateKey')
            ->with($key)
            ->willReturn(true);

        $this->layeredCache
            ->expects($this->once())
            ->method('hasItem')
            ->with($key)
            ->willReturn(true);

        $this->layeredCache
            ->expects($this->once())
            ->method('deleteItem')
            ->with($key)
            ->willReturn(true);

        // Act
        $result = $this->cache->delete($key);

        // Assert
        $this->assertTrue($result);
    }

    public function testClearSuccessfullyRemovesAllItems(): void
    {
        // Arrange
        $this->metricsCollector
            ->expects($this->once())
            ->method('recordClear');

        $this->layeredCache
            ->expects($this->once())
            ->method('clear')
            ->willReturn(true);

        // Act
        $result = $this->cache->clear();

        // Assert
        $this->assertTrue($result);
    }

    public function testIncrementPerformsAtomicIncrement(): void
    {
        // Arrange
        $key = 'counter-key';
        $step = 2;
        $initialValue = 10;
        $expectedValue = 15;

        $this->keyValidator
            ->expects($this->once())
            ->method('validateKey')
            ->with($key)
            ->willReturn(true);

        $this->layeredCache
            ->expects($this->once())
            ->method('increment')
            ->with($key, $step, $initialValue, null)
            ->willReturn($expectedValue);

        // Act
        $result = $this->cache->increment($key, $step, $initialValue);

        // Assert
        $this->assertSame($expectedValue, $result);
    }

    public function testAddReturnsFalseWhenKeyExists(): void
    {
        // Arrange
        $key = 'existing-key';
        $value = 'test-value';

        $this->keyValidator
            ->expects($this->once())
            ->method('validateKey')
            ->with($key)
            ->willReturn(true);

        $this->layeredCache
            ->expects($this->once())
            ->method('add')
            ->with($key, $this->anything(), null)
            ->willReturn(false);

        // Act
        $result = $this->cache->add($key, $value);

        // Assert
        $this->assertFalse($result);
    }

    public function testInvalidateTagsSuccessfully(): void
    {
        // Arrange
        $tags = ['tag1', 'tag2'];

        $this->layeredCache
            ->expects($this->once())
            ->method('invalidateTags')
            ->with($tags)
            ->willReturn(true);

        // Act
        $result = $this->cache->invalidateTags($tags);

        // Assert
        $this->assertTrue($result);
    }

    public function testGetMultipleReturnsArrayOfValues(): void
    {
        // Arrange
        $keys = ['key1', 'key2', 'key3'];
        $values = ['value1', 'value2', null];

        // Mock individual get calls
        $this->keyValidator
            ->expects($this->exactly(3))
            ->method('validateKey')
            ->willReturn(true);

        $this->layeredCache
            ->expects($this->exactly(3))
            ->method('getItem')
            ->willReturnCallback(function ($key) use ($keys, $values) {
                $index = array_search($key, $keys);
                $isHit = null !== $values[$index];

                return $this->createMockCacheItem($key, $values[$index], $isHit);
            });

        $this->serializer
            ->expects($this->exactly(2))
            ->method('unserialize')
            ->willReturnCallback(fn ($value) => $value);

        // Act
        $result = $this->cache->getMultiple($keys, 'default');

        // Assert
        $expected = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'default',
        ];
        $this->assertEquals($expected, $result);
    }

    public function testSetMultipleStoresAllValues(): void
    {
        // Arrange
        $values = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];

        $this->keyValidator
            ->expects($this->exactly(2))
            ->method('validateKey')
            ->willReturn(true);

        $this->serializer
            ->expects($this->exactly(2))
            ->method('serialize')
            ->willReturnCallback(fn ($value) => "serialized-{$value}");

        $this->layeredCache
            ->expects($this->exactly(2))
            ->method('save')
            ->willReturn(true);

        // Act
        $result = $this->cache->setMultiple($values);

        // Assert
        $this->assertTrue($result);
    }

    public function testHasReturnsTrueForExistingKey(): void
    {
        // Arrange
        $key = 'existing-key';

        $this->keyValidator
            ->expects($this->once())
            ->method('validateKey')
            ->with($key)
            ->willReturn(true);

        $this->layeredCache
            ->expects($this->once())
            ->method('hasItem')
            ->with($key)
            ->willReturn(true);

        // Act
        $result = $this->cache->has($key);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * @dataProvider invalidKeyProvider
     */
    public function testValidationRejectsInvalidKeys(string $invalidKey, string $expectedError): void
    {
        // Arrange
        $this->keyValidator
            ->expects($this->once())
            ->method('validateKey')
            ->with($invalidKey)
            ->willReturn(false);

        $this->keyValidator
            ->expects($this->once())
            ->method('getValidationErrors')
            ->with($invalidKey)
            ->willReturn([$expectedError]);

        // Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        $this->cache->get($invalidKey);
    }

    public static function invalidKeyProvider(): array
    {
        return [
            'empty string' => ['', 'empty key'],
            'too long' => [str_repeat('x', 300), 'too long'],
            'invalid chars' => ['key with spaces', 'invalid characters'],
            'reserved word' => ['null', 'reserved'],
        ];
    }

    public function testExceptionHandlingInGet(): void
    {
        // Arrange
        $key = 'test-key';
        $defaultValue = 'default';

        $this->keyValidator
            ->expects($this->once())
            ->method('validateKey')
            ->willReturn(true);

        $this->layeredCache
            ->expects($this->once())
            ->method('getItem')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Cache get operation failed', $this->arrayHasKey('error'));

        // Act
        $result = $this->cache->get($key, $defaultValue);

        // Assert
        $this->assertSame($defaultValue, $result);
    }

    public function testGetMetricsReturnsCollectorData(): void
    {
        // Arrange
        $expectedMetrics = [
            'hits' => 10,
            'misses' => 2,
            'hit_rate' => 0.83,
        ];
        $eventStats = ['events' => []];

        $this->metricsCollector
            ->expects($this->once())
            ->method('getMetrics')
            ->willReturn($expectedMetrics);

        $this->eventListener
            ->expects($this->once())
            ->method('getStats')
            ->willReturn($eventStats['events']);

        // Act
        $result = $this->cache->getMetrics();

        // Assert
        $this->assertEquals(array_merge($expectedMetrics, $eventStats), $result);
    }

    public function testAdvancedReturnsAdvancedInterface(): void
    {
        // Act
        $result = $this->cache->advanced();

        // Assert
        $this->assertInstanceOf(AdvancedCacheProxy::class, $result);
    }

    public function testWarmSuccessfullyPreloadsData(): void
    {
        // Arrange
        $data = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];
        $ttl = 3600;

        // Expect setMultiple to be called internally
        $this->keyValidator
            ->expects($this->exactly(2))
            ->method('validateKey')
            ->willReturn(true);

        // Act
        $this->cache->warm($data, $ttl);

        // Assert - no exception thrown means success
        $this->assertTrue(true);
    }

    private function createMockCacheItem(string $key, mixed $value, bool $isHit): MockObject
    {
        $item = $this->createMock(\KaririCode\Cache\Contract\CacheItem::class);
        $item->method('getKey')->willReturn($key);
        $item->method('get')->willReturn($value);
        $item->method('isHit')->willReturn($isHit);

        return $item;
    }
}
