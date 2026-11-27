# ADR-009: Backend-Persisted Entry Envelope

## Status

Accepted — 2026-05-29 (4.0.0 line, unreleased fix) · Revised V4.1.0

## Date

2026-05-29 · revised 2026-09-30

## Context

XFetch needs, for every entry, its logical expiry and the measured cost of
recomputing it (δ). The first 4.0 implementation kept that metadata in
process memory. PHP-FPM runs share-nothing: the next request starts with an
empty map, so XFetch could never fire across requests, and a cached `null`
could not be told apart from a miss.

### Prior Art

- **Symfony Cache Contracts** — store item metadata (expiry, computation
  time) with the value so that probabilistic early expiration works across
  processes.

## Decision

The metadata travels inside the stored value:

| Writer | Envelope | Fields |
|---|---|---|
| `Cache`, `LayeredCache` | `CacheEntry` (Value Object) | value, createdAt, expiresAt, delta, tags |
| `TagAwareCache` | `TaggedEntry` (DPO) | value, tagVersions, expiresAt, delta |

- `expiresAt` is the **logical** expiry (the fresh horizon for
  `rememberStale()`); the adapter TTL is the **physical** one.
- Readers accept raw values written by other clients and return them as-is.
- `CacheEntry` is a `final readonly class`; its derived values are query
  methods that take the current time (`isExpiredAt()`, `secondsLeftAt()`,
  `ageAt()`), so nothing time-dependent is stored and the methods stay pure
  (E-040, ADR-014).

### Revision 4.1.0

1. **Allowlist fixed.** With `PhpSerializer(allowedClasses: [...])` the
   envelope classes were rejected and every read returned
   `__PHP_Incomplete_Class`. `PhpSerializer` now always admits
   `CacheEntry` and `TaggedEntry`.
2. **`TaggedEntry` replaces the tag array envelope**, which a user array
   with the same keys could impersonate (ADR-012).
3. **`LayeredCache` stores the envelope**, so promotions know how long the
   value may still live and never extend it.
4. **Undecodable entries** (class renamed, format changed, corrupt payload)
   are deleted and reported as misses by every serializing adapter.

## Consequences

### Positive

- XFetch and stale-while-revalidate work across workers and servers.
- A cached `null` is a hit.
- Promotion between layers keeps the original lifetime.

### Negative

- About 170 bytes per entry with native `serialize()` (measured: 8 → 180 B
  for a one-character string, 1034 → 1204 B for 1 KiB), significant for
  very small values; igbinary shrinks it.
- The stored format belongs to this package: other applications reading the
  same keys see `CacheEntry` objects, not bare values.
- Renaming or moving the envelope classes invalidates stored entries (they
  become misses).

## Alternatives Considered

1. **Metadata in a sidecar key** — rejected: two round trips per read and
   the two keys can expire or be evicted independently.
2. **Metadata in process memory** — the 4.0.0 design; does not work in a
   share-nothing runtime.
3. **Packing metadata into a string header** — rejected for
   `ArrayAdapter`/`ApcuAdapter`, which store PHP values natively.

## References

- A. Vattani et al., "Optimal Probabilistic Cache Stampede Prevention",
  PVLDB 8(8), 2015 — https://www.vldb.org/pvldb/vol8/p886-vattani.pdf
- PHP manual, `unserialize()` and `allowed_classes` —
  https://www.php.net/manual/en/function.unserialize.php
- ARFA 1.3 — P1 (Immutable State Transformation)
