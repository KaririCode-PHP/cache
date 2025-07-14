<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Structural;

use KaririCode\Cache\Contract\CacheSerializer;

/**
 * SerializerAware interface.
 *
 * This interface defines methods for managing serializers.
 * Serializers are responsible for converting complex data types
 * into storable formats and vice versa.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface SerializerAware
{
    /**
     * Sets the serializer for the current instance.
     *
     * @param CacheSerializer $serializer the serializer to set
     *
     * @return SerializerAware returns the current instance to allow method chaining
     */
    public function setSerializer(CacheSerializer $serializer): SerializerAware;

    /**
     * Retrieves the current serializer.
     *
     * @return CacheSerializer the current serializer
     */
    public function getSerializer(): CacheSerializer;

    /**
     * Checks if a serializer is currently set.
     *
     * @return bool true if serializer is set, false otherwise
     */
    public function hasSerializer(): bool;
}
