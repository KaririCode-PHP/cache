<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service\DTO;

/**
 * An immutable Data Transfer Object to represent the result of a single health check.
 * Using a DTO improves type safety and code clarity over associative arrays.
 */
final class HealthCheckResult
{
    /**
     * @param string $checkName the name of the check performed
     * @param bool $isHealthy whether the check passed
     * @param string $message a descriptive message about the result
     * @param float $durationMs the duration of the check in milliseconds
     * @param int $timestamp the Unix timestamp when the check was performed
     */
    public function __construct(
        public readonly string $checkName,
        public readonly bool $isHealthy,
        public readonly string $message,
        public readonly float $durationMs,
        public readonly int $timestamp
    ) {
    }

    /**
     * Converts the result object to an array for diagnostics.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'check' => $this->checkName,
            'healthy' => $this->isHealthy,
            'message' => $this->message,
            'duration_ms' => $this->durationMs,
            'timestamp' => $this->timestamp,
        ];
    }
}
