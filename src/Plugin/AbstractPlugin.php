<?php

declare(strict_types=1);

namespace KaririCode\Cache\Plugin;

use KaririCode\Cache\Attribute\DriverFactory;
use KaririCode\Cache\Config\DriverManager;
use KaririCode\Cache\Contract\CachePlugin;

abstract class AbstractPlugin implements CachePlugin
{
    protected array $configuration;

    public function __construct(array $configuration)
    {
        $this->configuration = $configuration;
    }

    public function getName(): string
    {
        return static::class;
    }

    abstract public function register(DriverManager $driverManager): void;

    abstract public function getDriverName(): string;

    public function getFactoryClass(): ?string
    {
        $reflection = new \ReflectionClass($this);
        $attributes = $reflection->getAttributes(DriverFactory::class);

        if (empty($attributes)) {
            return null;
        }

        $driverFactory = $attributes[0]->newInstance();

        return $driverFactory->factoryClass;
    }

    protected function getConfiguration(): ?array
    {
        return $this->configuration;
    }
}
