# ADR-005: Composable Compression

## Status

Accepted — V4.0.0 · Revised V4.1.0

## Date

2026-03-01 · revised 2026-09-30

## Context

Large values (rendered fragments, API payloads, collections) cost network
bandwidth and backend memory on Redis and disk space on the filesystem
adapter. Compression helps only above a certain size and only for
compressible data, so it has to be optional and transparent to callers.

### Prior Art

- **Symfony Cache** — marshallers wrap serialization; `DeflateMarshaller`
  compresses the marshalled payload.
- **phpredis** — client-side compression options (`Redis::OPT_COMPRESSION`)
  when the extension is built with lzf, zstd or lz4.

## Decision

A `Compressor` contract sits between the `Serializer` and the backend of the
serializing adapters (`RedisAdapter`, `FilesystemAdapter`):

```
write: value → Serializer → Compressor → backend
read:  backend → Compressor → Serializer → value
```

| Implementation | Behaviour |
|---|---|
| `NullCompressor` (default) | pass-through |
| `ZlibCompressor(threshold: 1024, level: -1)` | gzip when the payload is at least `threshold` bytes **and** shrinks; one-byte marker `0x01` compressed, `0x00` raw |

`ArrayAdapter` and `ApcuAdapter` store PHP values natively and do not
compress.

### Revision 4.1.0

1. **Validation.** `threshold < 0` or `level` outside `-1..9` raise
   `ConfigurationException` (8640); a missing ext-zlib raises
   `ConfigurationException::extensionMissing()` (8641) at construction.
2. **Semantic failure.** A corrupt compressed payload raised a generic
   `\RuntimeException`; it now raises `BackendException::decompressionFailed()`
   (8605), and the adapters turn it into a miss and delete the entry.
3. **Legacy payloads.** Data whose first byte is neither marker (entries
   written before compression was enabled) is returned unchanged instead of
   losing its first byte. Native `serialize()` output never starts with
   `0x00` or `0x01`.

## Consequences

### Positive

- Compression is opt-in per adapter and invisible to `Cache`.
- Small or incompressible payloads pay one byte, not a failed gzip round.
- Switching serializer or compressor degrades to misses (undecodable entries
  are deleted), not to exceptions.

### Negative

- CPU cost on every read and write of large entries.
- The marker byte is a private format: other clients reading the same keys
  must use the same compressor.
- igbinary output starts with `0x00`; switching an existing igbinary cache
  to `ZlibCompressor` turns its entries into misses until they are rewritten.

## Alternatives Considered

1. **Always compress** — rejected: wastes CPU on small values and can grow
   incompressible ones.
2. **Compression inside the serializer** — rejected: couples two
   independent choices (format and size).
3. **Magic-number detection instead of a marker** — rejected: gzip data can
   be told apart, raw data cannot.

## References

- RFC 1952, GZIP file format — https://www.rfc-editor.org/rfc/rfc1952
- PHP manual, `gzencode()` — https://www.php.net/manual/en/function.gzencode.php
- ARFA 1.3 — P4 (Protocol Agnosticism)
