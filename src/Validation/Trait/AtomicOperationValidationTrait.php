<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Provides validation for atomic cache operations.
 *
 * This trait implements the Single Responsibility Principle (SRP) by focusing
 * exclusively on validation logic for operations such as increment and decrement.
 */
trait AtomicOperationValidationTrait
{
    /**
     * Validates the step for increment/decrement operations.
     *
     * @throws InvalidArgumentException if the step is invalid (zero)
     */
    protected function validateStep(int $step): void
    {
        if (0 === $step) {
            throw InvalidArgumentException::invalidValue('The step for increment/decrement operations cannot be zero.');
        }
    }
}
