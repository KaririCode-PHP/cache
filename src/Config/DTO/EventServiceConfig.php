<?php

declare(strict_types=1);

namespace KaririCode\Cache\Config\DTO;

use KaririCode\Contract\ImmutableValue;

/**
 * An immutable DTO that encapsulates all configuration for the EventService.
 */
final class EventServiceConfig implements ImmutableValue
{
    /**
     * @param bool $enabled toggles the entire event and logging system on or off
     * @param bool $logOnDispatchError toggles logging for when the dispatcher itself fails
     */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly bool $logOnDispatchError = true
    ) {
    }
}
