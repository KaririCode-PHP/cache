<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation;

use KaririCode\Contract\ImmutableValue;

/**
 * ValidationRules - New immutable validation rules class.
 */
final class ValidationRules implements ImmutableValue
{
    public function __construct(
        private readonly array $rules
    ) {
    }

    public function getRule(string $name): mixed
    {
        return $this->rules[$name] ?? null;
    }

    public function hasRule(string $name): bool
    {
        return isset($this->rules[$name]);
    }

    public function getAllRules(): array
    {
        return $this->rules;
    }

    public function withRule(string $name, mixed $value): self
    {
        $newRules = $this->rules;
        $newRules[$name] = $value;

        return new self($newRules);
    }

    public function withoutRule(string $name): self
    {
        $newRules = $this->rules;
        unset($newRules[$name]);

        return new self($newRules);
    }
}
