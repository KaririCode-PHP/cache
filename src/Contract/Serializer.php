<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Serializer interface for pluggable serialization.
 */
interface Serializer
{
    public function serialize(mixed $data): string;

    public function unserialize(string $data): mixed;

    public function canSerialize(mixed $data): bool;
}
