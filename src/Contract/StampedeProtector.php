<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Stampede protection interface.
 */
interface StampedeProtector
{
    public function get(string $key, callable $callback, $ttl = null): mixed;

    public function isEnabled(): bool;

    public function enable(): void;

    public function disable(): void;
}
