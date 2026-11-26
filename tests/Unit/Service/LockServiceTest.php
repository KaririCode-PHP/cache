<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Service;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Service\LockService;
use KaririCode\Contract\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Complete unit tests for the LockService class.
 *
 * This test suite validates all aspects of distributed locking, including acquisition,
 * release, timeout handling, exclusive execution, and proper resource
 * cleanup, according to SOLID principles and concurrent programming best practices.
 *
 * @category  Tests
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
#[CoversClass(LockService::class)]
final class LockServiceTest extends TestCase
{
    /**
     * @var (AtomicOperable&CacheItemPoolInterface)|MockObject
     */
    private $cacheMock;

    private Logger|MockObject $loggerMock;
    private LockService $lockService;

    /**
     * Sets up the test environment before each test method.
     * Mocks dependencies and initializes the LockService.
     */
    protected function setUp(): void
    {
        parent::setUp();
        /** @var AtomicOperable&CacheItemPoolInterface */
        $this->cacheMock = $this->createMockForIntersectionOfInterfaces([
            AtomicOperable::class,
            CacheItemPoolInterface::class,
        ]);
        $this->loggerMock = $this->createMock(Logger::class);

        $this->lockService = new LockService($this->cacheMock, $this->loggerMock);
    }

    /**
     * Tears down the test environment after each test.
     * Ensures all acquired locks are released to prevent state leakage and fatal errors.
     */
    protected function tearDown(): void
    {
        // Check if the property was initialized before trying to use it.
        // This is necessary because tests running in a separate process
        // will not trigger setUp() in the main process that calls tearDown().
        if (isset($this->lockService)) {
            $this->lockService->releaseAll();
        }
        parent::tearDown();
    }

    /**
     * Tests successful lock acquisition.
     * Verifies that the cache's `add` method is called correctly and the result is true.
     */
    public function testSuccessfulLockAcquisition(): void
    {
        // Arrange
        $key = 'test_lock';
        $ttl = 10;
        $expectedLockKey = 'lock:' . hash('sha256', $key);

        $this->cacheMock
            ->expects($this->once())
            ->method('add')
            ->with($expectedLockKey, 1, $ttl)
            ->willReturn(true);

        $this->loggerMock
            ->expects($this->once())
            ->method('debug')
            ->with('Lock acquired successfully.', new IsType(IsType::TYPE_ARRAY));

        // Act
        $result = $this->lockService->acquire($key, $ttl);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Tests lock acquisition failure when the lock is already held.
     * Simulates that the `add` method always fails, leading to a timeout.
     */
    public function testLockAcquisitionFailureWhenAlreadyLocked(): void
    {
        // Arrange
        $key = 'test_lock';
        $maxWaitTime = 0.1; // Short wait time for the test

        $this->cacheMock
            ->expects($this->atLeastOnce())
            ->method('add')
            ->willReturn(false); // Lock already exists

        $this->loggerMock
            ->expects($this->once())
            ->method('warning')
            ->with('Lock acquisition timed out.', new IsType(IsType::TYPE_ARRAY));

        // Act
        $result = $this->lockService->acquire($key, 10, $maxWaitTime);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Tests successful lock release.
     * Ensures a successfully acquired lock is released by calling `deleteItem`.
     */
    public function testSuccessfulLockRelease(): void
    {
        // Arrange
        $key = 'test_lock';
        $expectedLockKey = 'lock:' . hash('sha256', $key);

        // 1. Arrange and Act for the 'acquire' step first.
        $this->cacheMock->method('add')->willReturn(true);
        $this->lockService->acquire($key);

        // 2. Now, set expectations for the 'release' step.
        $this->cacheMock
            ->expects($this->once())
            ->method('deleteItem')
            ->with($expectedLockKey)
            ->willReturn(true);

        $this->loggerMock
            ->expects($this->once())
            ->method('debug')
            ->with('Lock released.', new IsType(IsType::TYPE_ARRAY));

        // 3. Act on the 'release' step.
        $result = $this->lockService->release($key);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Tests failure when trying to release a lock not acquired by the current instance.
     */
    public function testLockReleaseFailureWhenNotAcquiredByThisInstance(): void
    {
        // Arrange
        $key = 'test_lock';

        $this->loggerMock
            ->expects($this->once())
            ->method('warning')
            ->with('Attempted to release a lock that was not acquired by this instance.', ['key' => $key]);

        // Act
        $result = $this->lockService->release($key);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Tests the withLock method for exclusive callback execution.
     * Verifies lock acquisition, callback execution, and lock release.
     */
    public function testWithLockExecutesCallbackExclusively(): void
    {
        // Arrange
        $key = 'test_lock';
        $expectedResult = 'callback_result';
        $callbackExecuted = false;

        $callback = function () use ($expectedResult, &$callbackExecuted) {
            $callbackExecuted = true;

            return $expectedResult;
        };

        $this->cacheMock->method('add')->willReturn(true);
        $this->cacheMock->method('deleteItem')->willReturn(true);

        // Act
        $result = $this->lockService->withLock($key, $callback);

        // Assert
        $this->assertTrue($callbackExecuted);
        $this->assertSame($expectedResult, $result);
    }

    /**
     * Tests that withLock throws an exception when it cannot acquire the lock.
     */
    public function testWithLockThrowsExceptionWhenLockCannotBeAcquired(): void
    {
        // Arrange
        $key = 'test_lock';
        $maxWaitTime = 0.1;
        $callback = fn () => 'should_not_execute';

        $this->cacheMock->method('add')->willReturn(false);

        // Assert
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("Lock timeout for key '{$key}' after 0 seconds");

        // Act
        $this->lockService->withLock($key, $callback, 10, $maxWaitTime);
    }

    /**
     * Tests that withLock releases the lock even if the callback throws an exception.
     */
    public function testWithLockReleasesLockEvenWhenCallbackThrowsException(): void
    {
        // Arrange
        $key = 'test_lock';
        $callback = function () {
            throw new \RuntimeException('Callback error');
        };

        $this->cacheMock->method('add')->willReturn(true);
        $this->cacheMock
            ->expects($this->once())
            ->method('deleteItem') // This must be called
            ->willReturn(true);

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Callback error');

        // Act
        $this->lockService->withLock($key, $callback);
    }

    /**
     * Tests that the releaseAll method releases all acquired locks.
     */
    public function testReleaseAllReleasesAllHeldLocks(): void
    {
        // Arrange
        $keys = ['lock1', 'lock2', 'lock3'];

        $this->cacheMock->method('add')->willReturn(true);
        $this->cacheMock
            ->expects($this->exactly(3))
            ->method('deleteItem')
            ->willReturn(true);

        $this->loggerMock
            ->expects($this->once())
            ->method('info')
            ->with('Released all held locks.', ['count' => 3]);

        foreach ($keys as $key) {
            $this->lockService->acquire($key);
        }

        // Act
        $this->lockService->releaseAll();

        // Assert
        $this->addToAssertionCount(1);
    }

    /**
     * Tests that the class destructor releases any outstanding locks.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestructorReleasesHeldLocks(): void
    {
        // Arrange
        $key = 'test_lock';

        // Due to running in a separate process, we must re-initialize dependencies.
        /** @var AtomicOperable&CacheItemPoolInterface|MockObject $cacheMock */
        $cacheMock = $this->createMockForIntersectionOfInterfaces([
            AtomicOperable::class,
            CacheItemPoolInterface::class,
        ]);
        $loggerMock = $this->createMock(Logger::class);

        $cacheMock
            ->expects($this->once())
            ->method('add')
            ->willReturn(true);

        // This is the crucial expectation for the destructor
        $cacheMock
            ->expects($this->once())
            ->method('deleteItem')
            ->willReturn(true);

        $lockService = new LockService($cacheMock, $loggerMock);
        $lockService->acquire($key);

        // Act - The destructor is called automatically at the end of the separate process
        unset($lockService);
    }

    /**
     * Tests that complex key names are handled properly via hashing.
     *
     * @param string $complexKey a key with special characters or long length
     */
    #[DataProvider('complexKeyProvider')]
    public function testHandlesComplexKeyNames(string $complexKey): void
    {
        // Arrange
        $this->cacheMock
            ->expects($this->once())
            ->method('add')
            ->willReturn(true);

        // Act
        $result = $this->lockService->acquire($complexKey);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Data provider for complex key names.
     *
     * @return array<string, array<string>>
     */
    public static function complexKeyProvider(): array
    {
        return [
            'key with spaces' => ['user profile lock'],
            'key with special chars' => ['user:123:profile@update'],
            'very long key' => [str_repeat('long_key_', 20)],
            'unicode key' => ['用户锁定_123'],
            'key with slashes' => ['path/to/resource/lock'],
        ];
    }
}
