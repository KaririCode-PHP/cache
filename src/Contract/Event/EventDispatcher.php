<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Event;

/**
 * Contract for event dispatching operations.
 */
interface EventDispatcher
{
    public function dispatchCacheHit(string $key, mixed $value, float $duration = 0.0): void;

    public function dispatchCacheMiss(string $key): void;

    public function dispatchCacheSet(string $key, mixed $value, ?int $ttl = 0, float $duration = 0.0): void;

    public function dispatchCacheDelete(string $key, bool $success, float $duration = 0.0): void;

    public function dispatchCacheClear(float $duration = 0.0): void;

    public function dispatchTagInvalidation(iterable $tags, float $affectedKeys, float $duration = 0.0): void;

    public function dispatchAtomicOperation(string $operation, string $key, mixed $result, float $duration = 0.0, array $data = []): void;
}
