<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\DTO;

/**
 * DTO to hold the context of a security-related event.
 */
final class SecurityContext
{
    public function __construct(
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly int $timestamp
    ) {
    }
}
