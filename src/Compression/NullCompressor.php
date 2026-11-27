<?php

declare(strict_types=1);

namespace KaririCode\Cache\Compression;

use KaririCode\Cache\Contract\Compressor;

/**
 * Pass-through compressor, the default of the serializing adapters.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): Null Object behind the Compressor port.
 * Spec V4: stateless service.
 *
 * Preconditions: none.
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     $codec = new PayloadCodec(compressor: new NullCompressor());
 *
 * References: ADR-005.
 *
 * @see https://en.wikipedia.org/wiki/Null_object_pattern
 */
final readonly class NullCompressor implements Compressor
{
    #[\Override]
    public function compress(string $data): string
    {
        return $data;
    }

    #[\Override]
    public function decompress(string $data): string
    {
        return $data;
    }

    #[\Override]
    public function isAvailable(): bool
    {
        return true;
    }
}
