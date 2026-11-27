<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serialization;

use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Exception\BackendException;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * igbinary serializer: compact binary encoding of PHP values.
 *
 * Payloads are usually smaller than native serialize() output. igbinary
 * has no class allowlist, so use it only with backends that the
 * application alone can write to.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): interchangeable Serializer.
 * Spec V4: stateless service; fails fast when ext-igbinary is missing.
 *
 * Preconditions: ext-igbinary; only backends the application alone writes to.
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     $codec = new PayloadCodec(new IgbinarySerializer());
 *
 * References: ADR-009; SPEC-001 §2.2.
 *
 * @see https://github.com/igbinary/igbinary
 */
final readonly class IgbinarySerializer implements Serializer
{
    public function __construct()
    {
        if (!extension_loaded('igbinary')) {
            throw ConfigurationException::extensionMissing('igbinary');
        }
    }

    #[\Override]
    public function serialize(mixed $value): string
    {
        $data = igbinary_serialize($value);

        if (!is_string($data)) {
            throw BackendException::serializationFailed('igbinary_serialize() returned no data');
        }

        return $data;
    }

    #[\Override]
    public function unserialize(string $data): mixed
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $value = igbinary_unserialize($data);
        } finally {
            restore_error_handler();
        }

        if (null !== $warning) {
            throw BackendException::serializationFailed($warning);
        }

        return $value;
    }

    #[\Override]
    public function name(): string
    {
        return 'igbinary';
    }
}
