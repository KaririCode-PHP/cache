<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Structural;

use KaririCode\Cache\Contract\CacheValidator;

/**
 * ValidatorAware interface.
 *
 * This interface defines methods for managing cache validators.
 * Validators are responsible for validating cache keys, values,
 * and other cache-related data for consistency and security.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface ValidatorAware
{
    /**
     * Sets the validator for the current instance.
     *
     * @param CacheValidator $validator the validator to set
     *
     * @return ValidatorAware returns the current instance to allow method chaining
     */
    public function setValidator(CacheValidator $validator): ValidatorAware;

    /**
     * Retrieves the current validator.
     *
     * @return CacheValidator the current validator
     */
    public function getValidator(): CacheValidator;

    /**
     * Checks if a validator is currently set.
     *
     * @return bool true if validator is set, false otherwise
     */
    public function hasValidator(): bool;
}
