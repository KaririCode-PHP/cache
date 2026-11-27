<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Optional compression step between serialization and backend I/O.
 *
 * Encode path: value → Serializer → Compressor → backend. Decode path is
 * the reverse. Implementations decide whether compressing pays off.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): transparent to values and backends.
 * Spec V4: contract (strategy).
 *
 * Preconditions: decompress() receives output of compress() of the same implementation.
 * Thread-safety: implementations are stateless.
 *
 * Side effects: none.
 *
 * Example:
 *     $bytes = $compressor->decompress($compressor->compress($bytes));
 *
 * References: ADR-005.
 *
 * @see https://www.rfc-editor.org/rfc/rfc1952
 */
interface Compressor
{
    public function compress(string $data): string;

    /** @throws \KaririCode\Cache\Exception\CacheException when the payload is corrupt */
    public function decompress(string $data): string;

    public function isAvailable(): bool;
}
