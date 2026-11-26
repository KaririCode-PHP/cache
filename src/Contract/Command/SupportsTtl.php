<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Command;

/**
 * Defines a command that can be configured with a Time-To-Live.
 */
interface SupportsTtl
{
    public function withTtl(\DateInterval|int $ttl): self;
}
