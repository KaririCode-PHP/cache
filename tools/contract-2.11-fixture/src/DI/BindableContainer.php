<?php

declare(strict_types=1);

namespace KaririCode\Contract\DI;

interface BindableContainer
{
    public function get(string $id): mixed;

    public function has(string $id): bool;

    public function bind(string $abstract, callable|string|null $concrete, bool $singleton = false): void;

    public function instance(string $abstract, mixed $value): void;
}
