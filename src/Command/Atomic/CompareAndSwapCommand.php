<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Atomic;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\LayeredCacheManager;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command for a compare-and-swap (CAS) atomic operation.
 */
final class CompareAndSwapCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly LayeredCacheManager $cache,
        ValidationService $validator,
        private readonly SerializationService $serializer,
        EventService $events,
        MetricsService $metrics,
        private readonly string $key,
        private readonly mixed $expectedValue,
        private readonly mixed $newValue
    ) {
        parent::__construct($validator, $events, $metrics);
    }

    protected function validate(): void
    {
        $this->validator->validateKey($this->key);
        $this->validator->validateValue($this->expectedValue);
        $this->validator->validateValue($this->newValue);
    }

    protected function doExecute(): bool
    {
        $serializedExpected = $this->serializer->serialize($this->expectedValue, $this->serializerName);
        $serializedNew = $this->serializer->serialize($this->newValue, $this->serializerName);

        return $this->cache->compareAndSwap($this->key, $serializedExpected, $serializedNew, $this->ttl);
    }

    public function getName(): string
    {
        return 'COMPARE_AND_SWAP';
    }
}
