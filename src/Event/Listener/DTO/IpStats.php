<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\Listener\DTO;

/**
 * DTO to track security-related statistics for an IP address.
 */
final class IpStats
{
    /** @var list<int> */
    public array $operationTimestamps = [];

    public int $suspiciousActivityCount = 0;
    public int $lastSeen;

    public function __construct()
    {
        $this->lastSeen = time();
    }
}
