<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Scope;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Exception\InvalidKeyException;
use PHPUnit\Framework\TestCase;

final class ScopedCacheTest extends TestCase
{
    public function testScopesIsolateKeysOverOneBackend(): void
    {
        $cache = new Cache(new ArrayAdapter());
        $tenantA = $cache->withScope('tenant:a');
        $tenantB = $cache->withScope('tenant:b');

        $tenantA->set('config', 'A');
        $tenantB->set('config', 'B');

        self::assertSame('A', $tenantA->get('config'));
        self::assertSame('B', $tenantB->get('config'));
        self::assertSame('A', $cache->get('tenant:a:config'));
    }

    public function testNestedScopesAndBatchOperations(): void
    {
        $cache = new Cache(new ArrayAdapter());
        $users = $cache->withScope('tenant:1')->withScope('users');

        $users->setMultiple(['1' => 'ana', '2' => 'bia']);

        self::assertSame('ana', $cache->get('tenant:1:users:1'));
        self::assertSame(['1' => 'ana', '2' => 'bia'], $users->getMultiple(['1', '2']));
        self::assertTrue($users->deleteMultiple(['1', '2']));
        self::assertFalse($users->has('1'));
    }

    public function testRememberAndRememberStaleAreScoped(): void
    {
        $cache = new Cache(new ArrayAdapter());
        $scoped = $cache->withScope('s');

        self::assertSame(1, $scoped->remember('k', static fn(): int => 1, 60));
        self::assertSame(2, $scoped->rememberStale('stale', static fn(): int => 2, 10, 60));
        self::assertSame(1, $cache->get('s:k'));
    }

    public function testInvalidScopesAreRejected(): void
    {
        $cache = new Cache(new ArrayAdapter());

        foreach (['', 'a{b}'] as $scope) {
            try {
                $cache->withScope($scope);
                self::fail("scope '{$scope}' accepted");
            } catch (ConfigurationException $e) {
                self::assertSame(ConfigurationException::INVALID_OPTION, $e->getCode());
            }
        }
    }

    public function testKeysAreValidatedBeforePrefixing(): void
    {
        $this->expectException(InvalidKeyException::class);

        (new Cache(new ArrayAdapter()))->withScope('s')->get('');
    }
}
