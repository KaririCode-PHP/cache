<?php

declare(strict_types=1);

namespace KaririCode\Contract\DI;

interface ServiceProvider
{
    public function register(BindableContainer $container): void;

    public function boot(): void;
}
