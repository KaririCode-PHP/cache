<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Command;

/**
 * Defines a command that can use a custom serializer for its operation.
 */
interface SupportsCustomSerializer
{
    public function withSerializer(string $serializerName): self;
}
