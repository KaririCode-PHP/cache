<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Contract\ImmutableValue;

/**
 * Interface CacheSerializer.
 *
 * Defines the contract for cache serializers.
 * Cache serializers are responsible for converting data types into
 * storable formats and vice versa, enabling persistence of complex
 * data structures in cache backends.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheSerializer
{
    /**
     * Serializes data for cache storage.
     *
     * @param mixed $data the data to serialize
     *
     * @throws \RuntimeException if serialization fails
     *
     * @return string the serialized data
     */
    public function serialize(mixed $data): string;

    /**
     * Unserializes data from cache storage.
     *
     * @param string $serializedData the serialized data
     *
     * @throws \RuntimeException if unserialization fails
     *
     * @return mixed the unserialized data
     */
    public function unserialize(string $serializedData): mixed;

    /**
     * Checks if data can be serialized by this serializer.
     *
     * @param mixed $data the data to check
     *
     * @return bool true if data can be serialized, false otherwise
     */
    public function canSerialize(mixed $data): bool;

    /**
     * Gets the MIME type of the serialized data.
     *
     * @return string the MIME type identifier
     */
    public function getMimeType(): string;

    /**
     * Gets the serializer configuration.
     *
     * @return ImmutableValue serializer configuration as an immutable object
     */
    public function getConfiguration(): ImmutableValue;
}
