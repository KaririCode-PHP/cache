# ADR-010: TTL and Capacity Semantics

## Status

Accepted — V4.1.0

## Date

2026-09-30

## Context

The 4.0 adapters disagreed on edge values, verified on PHP 8.4 with real
backends:

| Input | ArrayAdapter | ApcuAdapter | RedisAdapter | FilesystemAdapter |
|---|---|---|---|---|
| `ttl = 0` | expired at next read | **stored forever** (APCu: 0 = no TTL) | `SETEX` error, `false` | expired at next read |
| `ttl = null` | no expiry | no expiry | no expiry | **unreadable** (`isset` on a null expiry) |
| `remove()` of a missing key | `false` | `false` | `false` | `false` |

`Cache(maxItems: 0)` evicted every entry, while `ArrayAdapter(maxItems: 0)`
meant "unbounded" (4.0 API).

### Prior Art

- **PSR-16** — a TTL of zero or less deletes the item; `delete()` returns
  false only on error.
- **Redis** — `maxmemory` with an eviction policy (`allkeys-lru`,
  `allkeys-lfu`, ...) bounds the dataset on the server.

## Decision

### TTL (every adapter and every cache)

| TTL | Meaning |
|---|---|
| `null` | no expiry |
| `> 0` | expires after that many seconds |
| `<= 0` | the entry is deleted; the write returns `true` |

`remove()`/`removeMultiple()` are idempotent: `true` when the key no longer
exists, `false` only when the backend refused.

### Capacity

- Capacity belongs to the eviction policy (`EvictionPolicy::$capacity`,
  default 10 000); `0` = unbounded, negative values raise
  `ConfigurationException` (8640). `ArrayAdapter(maxItems)` follows
  the same rule for its own FIFO overflow. Until 4.1.0 `Cache` took a
  separate `maxItems` argument; moving it into the policy keeps the
  constructor within the Specification's four-parameter budget (§13) and
  puts the limit next to the code that enforces it.
- The eviction policy is tracked **in process memory**. It bounds
  `ArrayAdapter` and long-running workers. Under PHP-FPM with a shared
  backend each request only sees the keys it wrote, so capacity must be
  enforced by the backend: Redis `maxmemory` + `maxmemory-policy`, APCu
  `apc.shm_size`, disk quota for the filesystem.
- A miss removes the key from the policy, and a TTL ≤ 0 write deletes
  instead of inserting, so expired entries do not occupy tracked capacity.

## Consequences

### Positive

- One behaviour for every backend, matching PSR-16.
- PSR bridges need no special cases for zero TTL or missing keys.

### Negative

- Code that relied on `remove()` returning `false` for missing keys must use
  `has()` first.
- The policy capacity does not bound a shared backend under PHP-FPM; the backend's
  own limits do.

## Alternatives Considered

1. **Reject TTL ≤ 0** — rejected: PSR-16 defines it as deletion, and the
   bridges would need a translation anyway.
2. **Share the eviction policy through the backend** — rejected: a
   read-modify-write per access on the backend, slower than letting the
   backend evict.

## References

- PSR-16, TTL and `delete()` — https://www.php-fig.org/psr/psr-16/
- PHP manual, `apcu_store()` (TTL 0 = no expiry) — https://www.php.net/manual/en/function.apcu-store.php
- Redis, key eviction — https://redis.io/docs/latest/develop/reference/eviction/
- ARFA 1.3 — P4 (Protocol Agnosticism)
