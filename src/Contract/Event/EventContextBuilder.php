<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Event;

/**
 * Contract for building event contexts.
 */
interface EventContextBuilder
{
    public function buildContext(string $key, string $eventType, array $additional = []): array;

    public function sanitizeContext(array $context): array;
}
