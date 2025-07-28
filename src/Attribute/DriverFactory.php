<?php

declare(strict_types=1);

namespace KaririCode\Cache\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class DriverFactory
{
    public function __construct(public string $factoryClass)
    {
    }
}
