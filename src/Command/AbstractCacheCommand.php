<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command;

use DateInterval;
use KaririCode\Cache\Contract\CacheCommand;
use KaririCode\Cache\Contract\Command\SupportsCustomSerializer;
use KaririCode\Cache\Contract\Command\SupportsTags;
use KaririCode\Cache\Contract\Command\SupportsTopics;
use KaririCode\Cache\Contract\Command\SupportsTtl;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Abstract base class for all cache commands.
 *
 * This class provides a foundational structure for every cache operation,
 * implementing the Template Method pattern via the `execute()` method. This ensures
 * a consistent execution lifecycle (validate, execute, record metrics) for all commands.
 * It also provides a fluent interface for optional parameter configuration.
 */
abstract class AbstractCacheCommand implements
    CacheCommand,
    SupportsTtl,
    SupportsTags,
    SupportsTopics,
    SupportsCustomSerializer
{
    protected ?int $ttl = null;
    protected array $tags = [];
    protected array $topics = [];
    protected array $metadata = [];
    protected ?string $serializerName = null;
    protected ?\DateTimeInterface $executedAt = null;

    /**
     * @param ValidationService $validator the service for input validation
     * @param EventService $events the service for dispatching events
     * @param MetricsService $metrics the service for recording metrics
     */
    public function __construct(
        protected readonly ValidationService $validator,
        protected readonly EventService $events,
        protected readonly MetricsService $metrics
    ) {
    }

    /**
     * Sets the Time-To-Live for this command's operation.
     *
     * @param int|\DateInterval|null $ttl the TTL value
     *
     * @return self a new instance of the command with the TTL set
     */
    public function withTtl(int|\DateInterval|null $ttl): self
    {
        $clone = clone $this;
        $clone->ttl = $this->normalizeTtl($ttl);

        return $clone;
    }

    /**
     * Sets the tags for this command's operation.
     *
     * @param string[] $tags an array of tags
     *
     * @return self a new instance of the command with the tags set
     */
    public function withTags(array $tags): self
    {
        $clone = clone $this;
        $clone->tags = $tags;

        return $clone;
    }

    /**
     * Sets the topics for this command's operation.
     *
     * @param string[] $topics an array of topics
     *
     * @return self a new instance of the command with the topics set
     */
    public function withTopics(array $topics): self
    {
        $clone = clone $this;
        $clone->topics = $topics;

        return $clone;
    }

    /**
     * Sets a custom serializer for this command's operation.
     *
     * @param string $serializerName the name of the registered serializer
     *
     * @return self a new instance of the command with the custom serializer set
     */
    public function withSerializer(string $serializerName): self
    {
        $clone = clone $this;
        $clone->serializerName = $serializerName;

        return $clone;
    }

    /**
     * Executes the command using the template method pattern.
     *
     * @throws \Throwable re-throws any exception caught during execution
     *
     * @return mixed the result of the command execution
     */
    final public function execute(): mixed
    {
        $startTime = microtime(true);
        $this->executedAt = new \DateTimeImmutable();
        $success = false;

        try {
            $this->validate();
            $this->beforeExecute();
            $result = $this->doExecute();
            $success = is_bool($result) ? $result : true;

            $duration = microtime(true) - $startTime;
            $this->afterExecute($result, $duration);

            return $result;
        } catch (\Throwable $e) {
            $this->onError($e);
            throw $e;
        } finally {
            $this->recordMetrics($startTime, $success);
        }
    }

    /**
     * Gets context data about the command for logging and debugging.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return [
            'command' => $this->getName(),
            'ttl' => $this->ttl,
            'tags' => $this->tags,
            'topics' => $this->topics,
            'serializer' => $this->serializerName,
            'executed_at' => $this->executedAt?->format('c'),
        ];
    }

    /**
     * The core logic of the command. To be implemented by concrete command classes.
     *
     * @return mixed the result of the specific operation
     */
    abstract protected function doExecute(): mixed;

    /**
     * Validates the parameters required for the command.
     */
    abstract protected function validate(): void;

    /**
     * Hook method to run before the main execution logic.
     */
    protected function beforeExecute(): void
    {
    }

    /**
     * Hook method to run after the main execution logic.
     *
     * @param mixed $result the result from doExecute()
     */
    protected function afterExecute(mixed $result, float $duration): void
    {
    }

    /**
     * Hook method to handle errors during execution.
     *
     * @param \Throwable $e the caught exception
     */
    protected function onError(\Throwable $e): void
    {
    }

    /**
     * Records performance metrics for the command execution.
     *
     * @param float $startTime the timestamp when execution started
     * @param bool $success whether the operation was successful
     */
    protected function recordMetrics(float $startTime, bool $success): void
    {
        $duration = microtime(true) - $startTime;
        $this->metrics->record($this->getName(), $duration, $success);
    }

    /**
     * Normalizes a TTL value from int or DateInterval to an integer of seconds.
     */
    private function normalizeTtl(int|\DateInterval|null $ttl): ?int
    {
        if (null === $ttl) {
            return null;
        }

        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            $future = $now->add($ttl);

            return $future->getTimestamp() - $now->getTimestamp();
        }

        return $ttl > 0 ? $ttl : null;
    }
}
