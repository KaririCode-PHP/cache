<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Builder\CacheOperationBuilder;
use KaririCode\Cache\Command\CommandExecutor;
use KaririCode\Cache\Command\CommandFactory;
use KaririCode\Cache\Contract\AdvancedCache;
use KaririCode\Cache\Contract\Behavioral\TopicSubscribable;
use KaririCode\Cache\Contract\TaggableCache;
use KaririCode\Cache\Metrics\CacheMetrics;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\HealthCheckService;
use KaririCode\Cache\Service\LockService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main Cache Facade.
 *
 * Provides a simple, unified public API for interacting with the complex caching subsystem.
 * It follows the Facade pattern, delegating all operations to specialized command
 * objects, which are created by a factory and executed by an executor.
 */
final class Cache implements AdvancedCache, TaggableCache, TopicSubscribable
{
    private readonly CommandExecutor $commandExecutor;
    private readonly CommandFactory $commandFactory;

    /**
     * Constructor with dependency injection of all required services.
     */
    public function __construct(
        private readonly string $name,
        // Low-level components
        private readonly LayeredCacheManager $layeredCache,
        // Services
        private readonly ValidationService $validator,
        private readonly SerializationService $serializer,
        private readonly EventService $events,
        private readonly MetricsService $metrics,
        private readonly HealthCheckService $health,
        private readonly LockService $locks, // LockService is available for more advanced use cases
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        $this->commandExecutor = new CommandExecutor($this->logger);
        $this->commandFactory = new CommandFactory(
            $this->layeredCache,
            $this->validator,
            $this->serializer,
            $this->events,
            $this->metrics
        );
    }

    // --- Fluent Interface ---

    /**
     * Returns a new fluent operation builder for chaining complex commands.
     */
    public function with(): CacheOperationBuilder
    {
        return new CacheOperationBuilder($this->commandFactory, $this->commandExecutor);
    }

    // --- Basic Operations ---

    public function get(string $key, mixed $default = null): mixed
    {
        $command = $this->commandFactory->createGetCommand($key, $default);

        return $this->commandExecutor->execute($command);
    }

    public function set(string $key, mixed $value): bool
    {
        $command = $this->commandFactory->createSetCommand($key, $value);

        return $this->commandExecutor->execute($command);
    }

    public function delete(string $key): bool
    {
        $command = $this->commandFactory->createDeleteCommand($key);

        return $this->commandExecutor->execute($command);
    }

    public function clear(): bool
    {
        $command = $this->commandFactory->createClearCommand();

        return $this->commandExecutor->execute($command);
    }

    public function has(string $key): bool
    {
        $command = $this->commandFactory->createHasCommand($key);

        return $this->commandExecutor->execute($command);
    }

    // --- Batch Operations ---

    public function getMultiple(iterable $keys): iterable
    {
        $command = $this->commandFactory->createGetMultipleCommand($keys);

        return $this->commandExecutor->execute($command);
    }

    public function setMultiple(iterable $values): bool
    {
        $command = $this->commandFactory->createSetMultipleCommand($values);

        return $this->commandExecutor->execute($command);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $command = $this->commandFactory->createDeleteMultipleCommand($keys);

        return $this->commandExecutor->execute($command);
    }

    // --- Atomic Operations ---

    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        $command = $this->commandFactory->createIncrementCommand($key, $step, $initialValue);

        return $this->commandExecutor->execute($command);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        $command = $this->commandFactory->createDecrementCommand($key, $step, $initialValue);

        return $this->commandExecutor->execute($command);
    }

    public function add(string $key, mixed $value): bool
    {
        $command = $this->commandFactory->createAddCommand($key, $value);

        return $this->commandExecutor->execute($command);
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool
    {
        $command = $this->commandFactory->createCompareAndSwapCommand($key, $expected, $value);

        return $this->commandExecutor->execute($command);
    }

    // --- Taggable Operations ---

    public function setWithTags(string $key, mixed $value, iterable $tags = []): bool
    {
        $command = $this->commandFactory->createSetWithTagsCommand($key, $value, $tags);

        return $this->commandExecutor->execute($command);
    }

    public function invalidateTags(iterable $tags): bool
    {
        $command = $this->commandFactory->createInvalidateTagsCommand($tags);

        return $this->commandExecutor->execute($command);
    }

    public function getByTags(iterable $tags): iterable
    {
        // This operation is more complex and typically used via the builder,
        // but can be offered here for completeness.
        return $this->with()->getByTags($tags);
    }

    public function getTags(): iterable
    {
        // This is a direct query on the driver, bypassing the command pattern for simplicity.
        return $this->layeredCache->getTags();
    }

    // --- Topic Operations ---

    public function subscribeTo(string $key, iterable $topics): void
    {
        $this->layeredCache->subscribeTo($key, $topics);
    }

    public function unsubscribeFrom(string $key, iterable $topics): void
    {
        $this->layeredCache->unsubscribeFrom($key, $topics);
    }

    public function invalidateTopics(iterable $topics): bool
    {
        return $this->layeredCache->invalidateTopics($topics);
    }

    public function getSubscribedTopics(string $key): array
    {
        return $this->layeredCache->getSubscribedTopics($key);
    }

    public function getSubscribersForTopic(string $topic): array
    {
        return $this->layeredCache->getSubscribersForTopic($topic);
    }

    // --- Metadata and Health ---

    public function getName(): string
    {
        return $this->name;
    }

    public function isHealthy(): bool
    {
        return $this->health->isHealthy();
    }

    public function getMetrics(): CacheMetrics
    {
        return new CacheMetrics($this->metrics->getMetrics());
    }

    public function getCapabilities(): array
    {
        return [];
    }

    // ========================================================================
    // Internal Access for Builder
    // ========================================================================

    public function getCommandExecutor(): CommandExecutor
    {
        return $this->commandExecutor;
    }

    public function getLayeredCache(): LayeredCacheManager
    {
        return $this->layeredCache;
    }
}
