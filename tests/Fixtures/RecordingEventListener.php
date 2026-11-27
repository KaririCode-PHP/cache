<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Fixtures;

use KaririCode\Cache\Contract\CacheEventListener;

final class RecordingEventListener implements CacheEventListener
{
    /** @var list<string> "event:key" */
    public array $events = [];

    public function onHit(string $key): void
    {
        $this->events[] = "hit:{$key}";
    }

    public function onMiss(string $key): void
    {
        $this->events[] = "miss:{$key}";
    }

    public function onWrite(string $key, ?int $ttl): void
    {
        $this->events[] = "write:{$key}";
    }

    public function onDelete(string $key): void
    {
        $this->events[] = "delete:{$key}";
    }

    public function onEviction(string $key, string $policyName): void
    {
        $this->events[] = "evict:{$key}";
    }

    public function onStampedeRecompute(string $key, float $gapSeconds): void
    {
        $this->events[] = "recompute:{$key}";
    }
}
