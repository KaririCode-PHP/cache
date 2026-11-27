<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Exception;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Bridge\Exception\Psr16CacheException;
use KaririCode\Cache\Bridge\Exception\Psr16InvalidArgumentException;
use KaririCode\Cache\Bridge\Exception\Psr6CacheException;
use KaririCode\Cache\Bridge\Exception\Psr6InvalidArgumentException;
use KaririCode\Cache\Bridge\Psr16Bridge;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Exception\BackendException;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Exception\ConnectionException;
use KaririCode\Cache\Exception\InvalidKeyException;
use KaririCode\Cache\Exception\StoreNotFoundException;
use KaririCode\Cache\Registry\CacheRegistry;
use KaririCode\Contract\Exception\Configuration;
use KaririCode\Contract\Exception\ContainerFailure;
use KaririCode\Contract\Exception\ExceptionInterface;
use KaririCode\Contract\Exception\NotFound;
use KaririCode\Contract\Exception\Protocol;
use KaririCode\Contract\Exception\Runtime;
use KaririCode\Contract\Exception\Validation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    private const int RANGE_MIN = 8600;
    private const int RANGE_MAX = 8699;

    private const array FAMILIES = [Validation::class, NotFound::class, Configuration::class, Protocol::class, ContainerFailure::class, Runtime::class];

    private const array CONCRETE = [
        BackendException::class, ConnectionException::class, StoreNotFoundException::class, InvalidKeyException::class,
        ConfigurationException::class, Psr16InvalidArgumentException::class, Psr16CacheException::class,
        Psr6InvalidArgumentException::class, Psr6CacheException::class,
    ];

    /**
     * @return iterable<string, array{\Throwable, int, class-string}>
     */
    public static function factories(): iterable
    {
        yield 'read' => [BackendException::readFailed(), 8600, Runtime::class];
        yield 'write' => [BackendException::writeFailed(), 8601, Runtime::class];
        yield 'delete' => [BackendException::deleteFailed(), 8602, Runtime::class];
        yield 'flush' => [BackendException::flushFailed(), 8603, Runtime::class];
        yield 'serialization' => [BackendException::serializationFailed('offset 0'), 8604, Runtime::class];
        yield 'decompression' => [BackendException::decompressionFailed(), 8605, Runtime::class];
        yield 'connection' => [ConnectionException::connectionFailed('redis'), 8610, Runtime::class];
        yield 'store' => [StoreNotFoundException::storeNotRegistered(), 8620, NotFound::class];
        yield 'empty key' => [InvalidKeyException::empty(), 8630, Validation::class];
        yield 'reserved characters' => [InvalidKeyException::reservedCharacters('{}'), 8631, Validation::class];
        yield 'reserved prefix' => [InvalidKeyException::reservedPrefix('__tag_version:'), 8632, Validation::class];
        yield 'invalid option' => [ConfigurationException::invalidOption('maxItems', 'must be >= 0'), 8640, Configuration::class];
        yield 'extension missing' => [ConfigurationException::extensionMissing('redis'), 8641, Configuration::class];
        yield 'psr-16 key' => [Psr16InvalidArgumentException::fromInvalidKey(InvalidKeyException::empty()), 8650, Validation::class];
        yield 'psr-16 failure' => [Psr16CacheException::fromCacheFailure(BackendException::flushFailed()), 8651, Runtime::class];
        yield 'psr-6 key' => [Psr6InvalidArgumentException::fromInvalidKey(InvalidKeyException::empty()), 8652, Validation::class];
        yield 'psr-6 failure' => [Psr6CacheException::fromCacheFailure(BackendException::flushFailed()), 8653, Runtime::class];
    }

    /**
     * @param class-string $family
     */
    #[DataProvider('factories')]
    public function testFactoryCodeFamilyAndMarker(\Throwable $exception, int $code, string $family): void
    {
        self::assertSame($code, $exception->getCode());
        self::assertGreaterThanOrEqual(self::RANGE_MIN, $code);
        self::assertLessThanOrEqual(self::RANGE_MAX, $code);
        self::assertInstanceOf($family, $exception);
        self::assertInstanceOf(CacheException::class, $exception);
        self::assertInstanceOf(ExceptionInterface::class, $exception);
    }

    public function testEachExceptionImplementsExactlyOneContractFamily(): void
    {
        foreach (self::CONCRETE as $class) {
            $families = array_values(array_filter(self::FAMILIES, static fn(string $family): bool => is_subclass_of($class, $family)));

            self::assertCount(1, $families, "{$class} must implement exactly one family");
            self::assertTrue(is_subclass_of($class, CacheException::class), $class);
            self::assertTrue((new \ReflectionClass($class))->isFinal(), $class);
        }
    }

    public function testEveryDeclaredCodeIsUniqueAndInRange(): void
    {
        $codes = [];

        foreach (self::CONCRETE as $class) {
            foreach ((new \ReflectionClass($class))->getReflectionConstants() as $constant) {
                $value = $constant->getValue();

                if ($constant->isPublic() && is_int($value)) {
                    self::assertGreaterThanOrEqual(self::RANGE_MIN, $value, "{$class}::{$constant->getName()}");
                    self::assertLessThanOrEqual(self::RANGE_MAX, $value, "{$class}::{$constant->getName()}");
                    $codes[] = $value;
                }
            }
        }

        self::assertCount(count($codes), array_unique($codes), 'codes must be unique');
        self::assertCount(17, $codes);
    }

    #[Group('security')]
    public function testMessagesNeverEchoARejectedValue(): void
    {
        $sensitive = 'user:alice@example.com';
        $messages = [];

        foreach (['{' . $sensitive, '', $sensitive . '/x'] as $key) {
            try {
                InvalidKeyException::validate($key);
            } catch (InvalidKeyException $e) {
                $messages[] = $e->getMessage();
            }
        }

        try {
            (new Psr16Bridge(new Cache(new ArrayAdapter())))->get($sensitive);
        } catch (Psr16InvalidArgumentException $e) {
            $messages[] = $e->getMessage();
        }

        try {
            (new CacheRegistry())->store($sensitive);
        } catch (StoreNotFoundException $e) {
            $messages[] = $e->getMessage();
        }

        try {
            new ArrayAdapter(maxItems: -987654);
        } catch (ConfigurationException $e) {
            $messages[] = $e->getMessage();
        }

        self::assertCount(6, $messages);

        foreach ($messages as $message) {
            self::assertStringNotContainsString('alice', $message);
            self::assertStringNotContainsString('987654', $message);
        }
    }

    public function testPreviousExceptionIsKept(): void
    {
        $cause = new \RuntimeException('socket closed');

        self::assertSame($cause, BackendException::readFailed($cause)->getPrevious());
        self::assertSame($cause, ConnectionException::connectionFailed('redis', $cause)->getPrevious());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => ['', InvalidKeyException::EMPTY_KEY];

        foreach (str_split('{}()/\@') as $char) {
            yield "reserved {$char}" => ["a{$char}b", InvalidKeyException::RESERVED_CHARACTERS];
        }
    }

    #[DataProvider('invalidKeys')]
    public function testNativeValidationRejects(string $key, int $code): void
    {
        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionCode($code);

        InvalidKeyException::validate($key);
    }

    public function testNativeValidationAcceptsColonAndCommonKeys(): void
    {
        foreach (['user:42', 'a.b_c-d', 'tenant:1:users:7', str_repeat('k', 250)] as $key) {
            InvalidKeyException::validate($key);
        }

        $this->addToAssertionCount(4);
    }

    public function testPsrStrictValidationRejectsColon(): void
    {
        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionCode(InvalidKeyException::RESERVED_CHARACTERS);

        InvalidKeyException::validate('user:42', psrStrict: true);
    }

    public function testStaleWindowValidation(): void
    {
        ConfigurationException::validateStaleWindow(freshTtl: 1, staleTtl: 1);

        foreach ([[0, 10], [10, 5]] as [$fresh, $stale]) {
            try {
                ConfigurationException::validateStaleWindow(freshTtl: $fresh, staleTtl: $stale);
                self::fail("window {$fresh}/{$stale} must be rejected");
            } catch (ConfigurationException $e) {
                self::assertSame(ConfigurationException::INVALID_OPTION, $e->getCode());
            }
        }
    }
}
