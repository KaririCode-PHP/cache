<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Config;

use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\ImmutableValue;

final class ApcuConfig implements ImmutableValue
{
    private const TAG_INDEX_PREFIX = 'tag_index::';
    private const TOPIC_INDEX_PREFIX = 'topic_index::';
    private const LOCK_PREFIX = 'lock::';

    public function __construct(
        public readonly string $keyPrefix = 'cache::',
        public readonly string $tagPrefix = 'tag::',
        public readonly string $topicPrefix = 'topic::',
        public readonly int $lockTimeout = 5,
        public readonly bool $enableStatistics = true,
        public readonly float $cleanupProbability = 0.01,
        public readonly int $retryAttempts = 3,
        public readonly float $retryDelay = 0.1
    ) {
        $this->validate();
    }

    public static function fromArray(array $config): self
    {
        return new self(
            keyPrefix: $config['key_prefix'] ?? 'cache::',
            tagPrefix: $config['tag_prefix'] ?? 'tag::',
            topicPrefix: $config['topic_prefix'] ?? 'topic::',
            lockTimeout: (int) ($config['lock_timeout'] ?? 5),
            enableStatistics: (bool) ($config['enable_statistics'] ?? true),
            cleanupProbability: (float) ($config['cleanup_probability'] ?? 0.01),
            retryAttempts: (int) ($config['retry_attempts'] ?? 3),
            retryDelay: (float) ($config['retry_delay'] ?? 0.1)
        );
    }

    public function validate(): void
    {
        if (empty($this->keyPrefix)) {
            throw ConfigurationException::invalidConfiguration('key_prefix', 'Key prefix cannot be empty');
        }
        if ($this->lockTimeout < 1 || $this->lockTimeout > 30) {
            throw ConfigurationException::invalidConfiguration('lock_timeout', 'Lock timeout must be between 1 and 30 seconds');
        }
        if ($this->cleanupProbability < 0 || $this->cleanupProbability > 1) {
            throw ConfigurationException::invalidConfiguration('cleanup_probability', 'Cleanup probability must be between 0 and 1');
        }
        if ($this->retryAttempts < 0) {
            throw ConfigurationException::invalidConfiguration('retry_attempts', 'Retry attempts must be non-negative');
        }
        if ($this->retryDelay < 0) {
            throw ConfigurationException::invalidConfiguration('retry_delay', 'Retry delay must be non-negative');
        }
    }

    public function getTagIndexPrefix(): string
    {
        return $this->keyPrefix . self::TAG_INDEX_PREFIX;
    }

    public function getTopicIndexPrefix(): string
    {
        return $this->keyPrefix . self::TOPIC_INDEX_PREFIX;
    }

    public function getLockPrefix(): string
    {
        return $this->keyPrefix . self::LOCK_PREFIX;
    }
}
