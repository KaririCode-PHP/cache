<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serialization;

use KaririCode\Cache\Compression\NullCompressor;
use KaririCode\Cache\Contract\Compressor;
use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Exception\BackendException;

/**
 * Encodes values for backends that store strings: Serializer, then
 * Compressor; decoding runs the reverse.
 *
 * Shared by RedisAdapter and FilesystemAdapter, which used to repeat the
 * same two calls and took the pair as two constructor parameters.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): format and compression are chosen by
 * configuration, not by the adapter.
 * Spec V4: stateless service (final readonly class).
 *
 * Preconditions: decode() receives a payload produced by encode() with the
 * same serializer and compressor.
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     $codec = new PayloadCodec(new PhpSerializer([Product::class]), new ZlibCompressor(threshold: 1024));
 *
 * References: ADR-005; ADR-009; SPEC-001 §7.
 *
 * @see https://www.php.net/manual/en/function.unserialize.php
 */
final readonly class PayloadCodec
{
    public function __construct(
        private Serializer $serializer = new PhpSerializer(),
        private Compressor $compressor = new NullCompressor(),
    ) {}

    /**
     * @throws BackendException 8604 when the value cannot be serialized
     */
    public function encode(mixed $value): string
    {
        return $this->compressor->compress($this->serializer->serialize($value));
    }

    /**
     * @throws BackendException 8604/8605 when the payload is corrupt or incompatible
     */
    public function decode(string $payload): mixed
    {
        return $this->serializer->unserialize($this->compressor->decompress($payload));
    }
}
