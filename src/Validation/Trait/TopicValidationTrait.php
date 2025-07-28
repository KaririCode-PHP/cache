<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\ValueObject\Topic;

/**
 * Provides topic validation functionality.
 *
 * This trait handles validation of cache topics for subscription-based
 * cache invalidation.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
trait TopicValidationTrait
{
    /**
     * Validates an array of Topic objects with improved type safety.
     *
     * @param array<Topic> $topics Array of Topic objects
     *
     * @throws InvalidArgumentException if any topic is invalid
     */
    protected function validateTopics(array $topics): void
    {
        $topicStrings = array_map(fn (Topic $topic) => (string) $topic, $topics);
        $this->validateTopicStrings($topicStrings);
    }

    /**
     * Validates an array of topic strings with enhanced checks.
     *
     * @param array<string> $topics Array of topic strings
     *
     * @throws InvalidArgumentException if any topic is invalid
     */
    protected function validateTopicStrings(array $topics): void
    {
        if (empty($topics)) {
            return;
        }

        foreach ($topics as $index => $topic) {
            if (!is_string($topic)) {
                throw InvalidArgumentException::invalidTopic('Topic must be a string, ' . get_debug_type($topic) . ' given');
            }

            $trimmedTopic = trim($topic);
            if (empty($trimmedTopic)) {
                throw InvalidArgumentException::invalidTopic(sprintf('Topic at index %d cannot be empty or whitespace-only', $index));
            }

            // Enhanced validation for topic format
            if (!$this->isValidTopicFormat($trimmedTopic)) {
                throw InvalidArgumentException::invalidTopic(sprintf('Topic "%s" at index %d contains invalid characters. Only alphanumeric characters, dots, underscores, colons, slashes, and hyphens are allowed', $trimmedTopic, $index));
            }

            // Check topic length
            if (strlen($trimmedTopic) > 255) {
                throw InvalidArgumentException::invalidTopic(sprintf('Topic at index %d exceeds maximum length of 255 characters', $index));
            }
        }
    }

    /**
     * Converts mixed topic types to Topic objects safely.
     *
     * @param array<mixed> $topics Array of mixed topic types
     *
     * @throws InvalidArgumentException if conversion fails
     *
     * @return array<Topic> Array of Topic objects
     */
    protected function createTopicObjects(array $topics): array
    {
        if (empty($topics)) {
            return [];
        }

        $topicObjects = [];

        foreach ($topics as $index => $topic) {
            try {
                if ($topic instanceof Topic) {
                    $topicObjects[] = $topic;
                } elseif (is_string($topic)) {
                    $trimmedTopic = trim($topic);
                    if (empty($trimmedTopic)) {
                        throw InvalidArgumentException::invalidTopic(sprintf('Topic at index %d cannot be empty', $index));
                    }
                    $topicObjects[] = new Topic($trimmedTopic);
                } elseif (is_scalar($topic)) {
                    // Convert scalar types to string
                    $stringTopic = (string) $topic;
                    $topicObjects[] = new Topic($stringTopic);
                } else {
                    throw InvalidArgumentException::invalidTopic(sprintf('Cannot convert topic at index %d of type %s to Topic object', $index, get_debug_type($topic)));
                }
            } catch (\Throwable $e) {
                throw InvalidArgumentException::invalidTopic(sprintf('Failed to create Topic object at index %d: %s', $index, $e->getMessage()));
            }
        }

        return $topicObjects;
    }

    /**
     * Extracts string values from mixed topic array.
     *
     * @param array<mixed> $topics Mixed topic array
     *
     * @return array<string> Array of topic strings
     */
    protected function extractTopicStrings(array $topics): array
    {
        return array_map(function (mixed $topic, int $index): string {
            if ($topic instanceof Topic) {
                return (string) $topic;
            }

            if (is_string($topic)) {
                return trim($topic);
            }

            if (is_scalar($topic)) {
                return (string) $topic;
            }

            throw InvalidArgumentException::invalidTopic(sprintf('Cannot extract string from topic at index %d of type %s', $index, get_debug_type($topic)));
        }, $topics, array_keys($topics));
    }

    /**
     * Validates topic format using enhanced pattern matching.
     */
    private function isValidTopicFormat(string $topic): bool
    {
        // Allow alphanumeric characters, dots, underscores, colons, slashes, and hyphens
        // Topics can be hierarchical (e.g., "user/profile/update")
        return 1 === preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:\/\-]*[a-zA-Z0-9]$|^[a-zA-Z0-9]$/', $topic);
    }
}
