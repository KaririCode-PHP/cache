<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Converts PHP values to bytes for backends that store strings.
 *
 * unserialize() raises CacheException for payloads it cannot decode;
 * adapters turn that into a miss and delete the entry.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): format chosen by configuration.
 * Spec V4: contract; serialization lives in infrastructure, never on domain objects (Serialization Policy).
 *
 * Preconditions: unserialize() receives output of serialize() of the same implementation.
 * Thread-safety: implementations are stateless.
 *
 * Side effects: none.
 *
 * Example:
 *     $value = $serializer->unserialize($serializer->serialize($value));
 *
 * References: ADR-009; SPEC-001 §2.2.
 *
 * @see https://www.php.net/manual/en/function.serialize.php
 */
interface Serializer
{
    /** @throws \KaririCode\Cache\Exception\CacheException */
    public function serialize(mixed $value): string;

    /** @throws \KaririCode\Cache\Exception\CacheException */
    public function unserialize(string $data): mixed;

    public function name(): string;
}
