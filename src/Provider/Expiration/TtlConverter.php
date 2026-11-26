<?php

declare(strict_types=1);

namespace KaririCode\Cache\Provider\Expiration;

use DateInterval;
use DateTimeInterface;
use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Converter responsible solely for TTL format transformations.
 *
 * Follows SRP by handling only conversion between different TTL formats:
 * DateInterval, DateTimeInterface, int (seconds), and null values.
 *
 * @author Walmir Silva <walmir.silva@kariricode.org>
 *
 * @see "Clean Code" by Robert C. Martin - Single Responsibility Principle
 */
final readonly class TtlConverter
{
    /**
     * Converts various TTL formats to seconds.
     *
     * @param mixed $ttl The TTL value to convert
     *
     * @throws InvalidArgumentException For invalid TTL types
     *
     * @return int|null Seconds or null for permanent cache
     */
    public function toSeconds(mixed $ttl): ?int
    {
        return match (true) {
            null === $ttl => null,
            is_int($ttl) => $this->validateAndReturnSeconds($ttl),
            $ttl instanceof \DateInterval => $this->convertDateIntervalToSeconds($ttl),
            $ttl instanceof \DateTimeInterface => $this->convertDateTimeToSeconds($ttl),
            default => throw InvalidArgumentException::invalidTtl('TTL must be an integer, DateInterval, DateTimeInterface, or null.'),
        };
    }

    /**
     * Creates DateTimeInterface from seconds offset.
     */
    public function fromSeconds(int $seconds): \DateTimeInterface
    {
        if ($seconds <= 0) {
            throw InvalidArgumentException::invalidTtl('TTL seconds must be positive');
        }

        // The try-catch block was removed as `new \DateTimeImmutable` is highly unlikely to fail
        // with a positive integer, making the catch block unreachable code.
        return new \DateTimeImmutable("+{$seconds} seconds");
    }

    /**
     * Validates TTL without conversion - useful for upfront validation.
     */
    public function isValid(mixed $ttl): bool
    {
        try {
            $this->toSeconds($ttl);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function validateAndReturnSeconds(int $seconds): int
    {
        if ($seconds < 0) {
            throw InvalidArgumentException::invalidTtl('TTL cannot be negative');
        }

        return $seconds;
    }

    private function convertDateIntervalToSeconds(\DateInterval $interval): int
    {
        // The try-catch block was removed as `DateTimeImmutable::add` is highly robust
        // with a valid DateInterval object, making the catch block unreachable.
        $now = new \DateTimeImmutable();
        $future = $now->add($interval);
        $seconds = $future->getTimestamp() - $now->getTimestamp();

        return $this->validateAndReturnSeconds($seconds);
    }

    private function convertDateTimeToSeconds(\DateTimeInterface $dateTime): int
    {
        $now = new \DateTimeImmutable();
        $timestamp = $dateTime instanceof \DateTimeImmutable
            ? $dateTime->getTimestamp()
            : \DateTimeImmutable::createFromInterface($dateTime)->getTimestamp();

        $seconds = $timestamp - $now->getTimestamp();

        return $this->validateAndReturnSeconds($seconds);
    }
}
