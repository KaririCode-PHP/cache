<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serialization;

use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Exception\BackendException;
use KaririCode\Cache\ValueObject\CacheEntry;
use KaririCode\Cache\ValueObject\TaggedEntry;

/**
 * Native serialize()/unserialize() with an optional class allowlist.
 *
 * The envelopes this package persists (CacheEntry, TaggedEntry) are always
 * added to a restrictive allowlist; without that, a hardened allowlist
 * turned every cached entry into __PHP_Incomplete_Class (fixed in 4.1.0).
 * Malformed payloads raise BackendException::serializationFailed() instead
 * of emitting a PHP warning and returning false.
 *
 * Security: the PHP manual warns against unserializing untrusted input
 * even with allowed_classes. Point the cache only at backends that the
 * application alone can write to, and prefer an explicit allowlist.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): adapters depend on the Serializer
 * contract, not on a format.
 * Spec V4: stateless service (final readonly class).
 *
 * Preconditions: payloads come from backends the application alone writes to.
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: unserialize() installs an error handler for its own duration.
 *
 * Example:
 *     $codec = new PayloadCodec(new PhpSerializer(allowedClasses: [Product::class]));
 *
 * References: ADR-009; SPEC-001 §2.2.
 *
 * @see https://www.php.net/manual/en/function.unserialize.php
 */
final readonly class PhpSerializer implements Serializer
{
    private const array ENVELOPE_CLASSES = [CacheEntry::class, TaggedEntry::class];

    /** @var list<class-string>|true */
    private array|bool $allowedClasses;

    /**
     * @param list<class-string>|true $allowedClasses true = any class;
     *        a list = only those classes (package envelopes are added).
     */
    public function __construct(array|true $allowedClasses = true)
    {
        $this->allowedClasses = true === $allowedClasses
            ? true
            : array_values(array_unique([...$allowedClasses, ...self::ENVELOPE_CLASSES]));
    }

    #[\Override]
    public function serialize(mixed $value): string
    {
        try {
            return \serialize($value);
        } catch (\Throwable $e) {
            throw BackendException::serializationFailed($e->getMessage(), $e);
        }
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
            $value = \unserialize($data, ['allowed_classes' => $this->allowedClasses]);
        } catch (\Throwable $e) {
            throw BackendException::serializationFailed($e->getMessage(), $e);
        } finally {
            restore_error_handler();
        }

        if (null !== $warning || (false === $value && 'b:0;' !== $data)) {
            throw BackendException::serializationFailed($warning ?? 'malformed payload');
        }

        return $value;
    }

    #[\Override]
    public function name(): string
    {
        return 'php';
    }
}
