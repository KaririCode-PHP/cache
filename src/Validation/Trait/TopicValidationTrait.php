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
     * Validates an array of Topic objects.
     *
     * @param array<Topic> $topics Array of Topic objects
     *
     * @throws InvalidArgumentException if any topic is invalid
     */
    protected function validateTopics(array $topics): void
    {
        foreach ($topics as $topic) {
            if (!$topic instanceof Topic) {
                throw InvalidArgumentException::invalidTopic('Expected Topic object, got ' . get_debug_type($topic));
            }
        }
    }

    /**
     * Validates an array of topic strings.
     *
     * @param array<string> $topics Array of topic strings
     *
     * @throws InvalidArgumentException if any topic is invalid
     */
    protected function validateTopicStrings(array $topics): void
    {
        foreach ($topics as $topic) {
            if (!is_string($topic)) {
                throw InvalidArgumentException::invalidTopic('Topic must be a string, ' . gettype($topic) . ' given');
            }

            if (empty(trim($topic))) {
                throw InvalidArgumentException::invalidTopic('Topic cannot be empty');
            }

            // Validate topic format
            if (!preg_match('/^[a-zA-Z0-9_.:-]+$/', $topic)) {
                throw InvalidArgumentException::invalidTopic("Topic contains invalid characters: '{$topic}'");
            }
        }
    }

    /**
     * Converts topic strings to Topic objects.
     *
     * @param array<string> $topicStrings Array of topic strings
     *
     * @return array<Topic> Array of Topic objects
     */
    protected function createTopicObjects(array $topicStrings): array
    {
        $this->validateTopicStrings($topicStrings);

        return array_map(
            fn (string $topic) => new Topic($topic),
            $topicStrings
        );
    }
}
