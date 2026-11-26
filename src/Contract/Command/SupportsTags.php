<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Command;

/**
 * Defines a command that can be configured with tags.
 */
interface SupportsTags
{
    public function withTags(array $tags): self;
}
