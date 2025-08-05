<?php

declare(strict_types=1);

namespace KaririCode\Cache\Item;

use KaririCode\Cache\Contract\CacheItem as CacheItemContract;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\ValueObject\Tag;
use Psr\Cache\CacheItemInterface;

/**
 * Enhanced CacheItem implementation with proper TTL/expiration handling.
 *
 * This class implements both PSR-6 CacheItemInterface and the KaririCode
 * CacheItem contract, providing comprehensive cache item functionality
 * with support for tags, topics, and proper expiration handling.
 *
 * Key improvements:
 * - Consistent use of DateTimeImmutable for immutability
 * - Support for DateInterval, int, and DateTime TTL values
 * - Enhanced validation for all parameters
 * - Proper tag and topic management
 * - Type safety with strict typing
 * - Comprehensive documentation
 *
 * Design Patterns Applied:
 * - Value Object: Immutable cache item representation
 * - Builder Pattern: Fluent interface for configuration
 * - Strategy Pattern: Flexible expiration handling
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 * @see       PSR-6: Caching Interface
 */
final class CacheItem implements CacheItemContract, CacheItemInterface
{
    private mixed $value = null;
    private bool $isHit = false;
    private ?\DateTimeInterface $expiration = null;

    /** @var array<string> */
    private array $tags = [];

    /** @var array<string> */
    private array $topics = [];

    /**
     * Constructor with enhanced validation.
     *
     * @param string $key The cache key
     * @param mixed $value Optional initial value
     * @param bool $isHit Whether this item represents a cache hit
     *
     * @throws InvalidArgumentException When key is invalid
     */
    public function __construct(
        private readonly string $key,
        mixed $value = null,
        bool $isHit = false
    ) {
        $this->validateKey($key);

        if (null !== $value) {
            $this->set($value);
        }

        $this->isHit = $isHit;
    }

    // ========================================================================
    // PSR-6 CacheItemInterface Implementation
    // ========================================================================

    /**
     * Returns the key for the current cache item.
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Retrieves the value of the item from the cache associated with this object's key.
     */
    public function get(): mixed
    {
        return $this->isHit ? $this->value : null;
    }

    /**
     * Confirms if the cache item lookup resulted in a cache hit.
     */
    public function isHit(): bool
    {
        return $this->isHit;
    }

    /**
     * Sets the value represented by this cache item.
     *
     * @param mixed $value The serializable value to be stored
     *
     * @throws InvalidArgumentException When value cannot be serialized
     *
     * @return static The invoked object for method chaining
     */
    public function set(mixed $value): static
    {
        $this->validateValue($value);
        $this->value = $value;

        return $this;
    }

    /**
     * Sets the expiration time for this cache item.
     *
     * @param \DateTimeInterface|null $expiration The point in time after which the item MUST be considered expired
     *
     * @return static The called object for method chaining
     */
    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        if (null !== $expiration) {
            // Ensure we use DateTimeImmutable for immutability
            $this->expiration = $expiration instanceof \DateTimeImmutable
                ? $expiration
                : \DateTimeImmutable::createFromInterface($expiration);
        } else {
            $this->expiration = null;
        }

        return $this;
    }

    /**
     * Sets the expiration time for this cache item using a relative time.
     *
     * @param \DateInterval|int|null $time The period of time from the present after which the item MUST be considered expired
     *
     * @throws InvalidArgumentException When TTL value is invalid
     *
     * @return static The called object for method chaining
     */
    public function expiresAfter(\DateInterval|int|null $time): static
    {
        if (null === $time) {
            $this->expiration = null;

            return $this;
        }

        $now = new \DateTimeImmutable();

        if ($time instanceof \DateInterval) {
            $this->expiration = $now->add($time);
        } elseif (is_int($time)) {
            if ($time < 0) {
                throw InvalidArgumentException::invalidTtl('TTL cannot be negative');
            }

            $this->expiration = $time > 0
                ? $now->modify("+{$time} seconds")
                : null; // 0 means no expiration
        } else {
            throw InvalidArgumentException::invalidTtl('TTL must be DateInterval, int, or null');
        }

        return $this;
    }

    // ========================================================================
    // KaririCode CacheItem Contract Implementation
    // ========================================================================

    /**
     * Gets the expiration time for this cache item.
     */
    public function getExpiration(): ?\DateTimeInterface
    {
        return $this->expiration;
    }

    /**
     * Associates tags with the cache item for grouped operations.
     *
     * @param array<Tag> $tags Array of Tag objects to associate with the cache item
     *
     * @throws InvalidArgumentException When tags are invalid
     *
     * @return static The invoked object for method chaining
     *
     * @example
     * ```php
     * $item->tag([new Tag('user'), new Tag('profile')]);
     * ```
     */
    public function tag(array $tags): static
    {
        $this->validateTags($tags);

        $tagStrings = array_map(fn (Tag $tag): string => (string) $tag, $tags);
        $this->tags = array_unique(array_merge($this->tags, $tagStrings));

        return $this;
    }

    /**
     * Gets all tags associated with this cache item.
     *
     * @return array<string> Array of tag names
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Subscribes the cache item to topics for event-driven invalidation.
     *
     * @param array<string> $topics Array of topic names to subscribe to
     *
     * @throws InvalidArgumentException When topics are invalid
     *
     * @return static The invoked object for method chaining
     *
     * @example
     * ```php
     * $item->subscribeTo(['user.profile.updated', 'user.permissions.changed']);
     * ```
     */
    public function subscribeTo(array $topics): static
    {
        $this->validateTopics($topics);
        $this->topics = array_unique(array_merge($this->topics, $topics));

        return $this;
    }

    /**
     * Gets all topics this cache item is subscribed to.
     *
     * @return array<string> Array of topic names
     */
    public function getTopics(): array
    {
        return $this->topics;
    }

    // ========================================================================
    // Additional Utility Methods
    // ========================================================================

    /**
     * Checks if the cache item has expired.
     */
    public function isExpired(): bool
    {
        if (null === $this->expiration) {
            return false;
        }

        return $this->expiration <= new \DateTimeImmutable();
    }

    /**
     * Gets the remaining time until expiration in seconds.
     *
     * @return int|null Seconds until expiration, null if no expiration set, 0 if already expired
     */
    public function getTtlSeconds(): ?int
    {
        if (null === $this->expiration) {
            return null;
        }

        $now = new \DateTimeImmutable();
        $diff = $this->expiration->getTimestamp() - $now->getTimestamp();

        return max(0, $diff);
    }

    /**
     * Checks if the cache item has any tags.
     */
    public function hasTags(): bool
    {
        return !empty($this->tags);
    }

    /**
     * Checks if the cache item is subscribed to any topics.
     */
    public function hasTopics(): bool
    {
        return !empty($this->topics);
    }

    /**
     * Checks if the cache item has a specific tag.
     */
    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    /**
     * Checks if the cache item is subscribed to a specific topic.
     */
    public function hasTopicSubscription(string $topic): bool
    {
        return in_array($topic, $this->topics, true);
    }

    /**
     * Gets the value type for debugging and monitoring.
     */
    public function getValueType(): string
    {
        return get_debug_type($this->value);
    }

    /**
     * Gets the serialized size of the value for monitoring.
     */
    public function getValueSize(): int
    {
        try {
            return strlen(serialize($this->value));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Creates a copy of this cache item with a new key.
     */
    public function withKey(string $newKey): self
    {
        $copy = new self($newKey, $this->value, $this->isHit);
        $copy->expiration = $this->expiration;
        $copy->tags = $this->tags;
        $copy->topics = $this->topics;

        return $copy;
    }

    /**
     * Creates a copy of this cache item with a new expiration.
     */
    public function withExpiration(?\DateTimeInterface $expiration): self
    {
        $copy = clone $this;
        $copy->expiresAt($expiration);

        return $copy;
    }

    /**
     * Marks this item as a cache hit.
     */
    public function markAsHit(): self
    {
        $this->isHit = true;

        return $this;
    }

    /**
     * Marks this item as a cache miss.
     */
    public function markAsMiss(): self
    {
        $this->isHit = false;
        $this->value = null;

        return $this;
    }

    // ========================================================================
    // Private Validation Methods
    // ========================================================================

    /**
     * Validates a cache key according to PSR-6 standards.
     *
     * @throws InvalidArgumentException When key is invalid
     */
    private function validateKey(string $key): void
    {
        if (empty($key)) {
            throw InvalidArgumentException::emptyKey();
        }

        if (strlen($key) > 250) {
            throw InvalidArgumentException::keyTooLong($key);
        }

        // PSR-6 reserves these characters
        if (preg_match('/[{}()\/@:]/', $key)) {
            throw InvalidArgumentException::invalidKeyFormat($key);
        }
    }

    /**
     * Validates a cache value to ensure it can be serialized.
     *
     * @throws InvalidArgumentException When value cannot be serialized
     */
    private function validateValue(mixed $value): void
    {
        // Check for resources which cannot be serialized
        if (is_resource($value)) {
            throw InvalidArgumentException::invalidValue('Resources cannot be cached');
        }

        // Test serialization capability
        try {
            serialize($value);
        } catch (\Throwable $exception) {
            throw InvalidArgumentException::invalidValue('Value cannot be serialized: ' . $exception->getMessage());
        }
    }

    /**
     * Validates an array of tags.
     *
     * @param array<Tag> $tags
     *
     * @throws InvalidArgumentException When tags are invalid
     */
    private function validateTags(array $tags): void
    {
        if (empty($tags)) {
            return;
        }

        foreach ($tags as $tag) {
            if (!$tag instanceof Tag) {
                throw InvalidArgumentException::invalidTag('All tags must be Tag objects');
            }
        }

        if (count($tags) > 50) { // Reasonable limit
            throw InvalidArgumentException::invalidTag('Maximum of 50 tags allowed per cache item');
        }
    }

    /**
     * Validates an array of topics.
     *
     * @param array<string> $topics
     *
     * @throws InvalidArgumentException When topics are invalid
     */
    private function validateTopics(array $topics): void
    {
        if (empty($topics)) {
            return;
        }

        foreach ($topics as $topic) {
            if (!is_string($topic) || empty(trim($topic))) {
                throw InvalidArgumentException::invalidTopic('All topics must be non-empty strings');
            }

            if (strlen($topic) > 100) {
                throw InvalidArgumentException::invalidTopic('Topic name too long (max 100 characters)');
            }

            // Basic topic name validation
            if (!preg_match('/^[a-zA-Z0-9_.:-]+$/', $topic)) {
                throw InvalidArgumentException::invalidTopic("Topic contains invalid characters: '{$topic}'");
            }
        }

        if (count($topics) > 10) { // Reasonable limit
            throw InvalidArgumentException::invalidTopic('Maximum of 10 topics allowed per cache item');
        }
    }
}
