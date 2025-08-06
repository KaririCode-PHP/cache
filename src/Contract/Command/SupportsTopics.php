<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Command;

/**
 * Defines a command that can be configured with topics.
 */
interface SupportsTopics
{
    public function withTopics(array $topics): self;
}
