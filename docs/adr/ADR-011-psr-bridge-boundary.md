# ADR-011: PSR-6 / PSR-16 Bridge Boundary

## Status

Accepted — V4.0.0 · Revised V4.1.0

## Date

2026-03-01 · revised 2026-09-30

## Context

The native API differs from PSR-6/PSR-16 on purpose (the colon is a valid
key character for scoping, `remember()`/`rememberStale()` exist). Code that
consumes the PSR interfaces must still get exactly PSR behaviour. In 4.0 the
bridges passed native semantics through: `:` was accepted, invalid keys
raised an exception that did not implement the PSR interfaces, PSR-6
deferred items were invisible before `commit()`, and a cached `null` was a
miss.

### Prior Art

- **PSR-6** — keys must not support `{}()/\@:`; an expired value is always a
  miss; deferred items must eventually be persisted and requests for a
  deferred item must return it; a destructor is an acceptable way to
  persist.
- **Symfony `Psr16Cache` / pools** — translate at the boundary with
  exception classes implementing both PSR interfaces.

## Decision

`Psr16Bridge` and `Psr6Bridge` enforce the PSR rules; the native API keeps
its own.

| Rule | Native API | Bridges |
|---|---|---|
| Reserved key characters | `{}()/\@` | `{}()/\@:` |
| Invalid key | `InvalidKeyException` (8630–8632) | `Psr16InvalidArgumentException` (8650) / `Psr6InvalidArgumentException` (8652) |
| Backend failure | `CacheException` | `Psr16CacheException` (8651) / `Psr6CacheException` (8653), both still `CacheException` |
| TTL ≤ 0 (int or `DateInterval`) | delete | delete |
| Deleting a missing key | `true` | `true` |

PSR-6 specifics:

- `getItem()`/`hasItem()` return deferred items before `commit()`
  (unless their own expiration has passed);
- pending deferred items are committed in `__destruct()`;
- a cached `null` is a hit (`has()` is consulted when `get()` returns null);
- all keys of `getItems()`/`deleteItems()` are validated before any access.

`Psr6CacheItem` keeps the getters PSR-6 mandates (`getKey()`, `get()`,
`isHit()`); Spec V4's getter ban does not apply to external contracts.

## Consequences

### Positive

- Libraries written against PSR-6/PSR-16 behave as they would on any
  conforming pool.
- The original exception is kept as `previous`, with its native code.

### Negative

- Keys with `:` written through the native API cannot be read through a
  bridge; use `withScope()` natively, or keys without `:` when both APIs
  share data.
- `Psr6Bridge` holds deferred items in memory until commit or destruction.

## Alternatives Considered

1. **One exception class implementing both PSR interfaces** — rejected:
   both packages would become mandatory for the class to load.
2. **Strict PSR keys everywhere** — rejected: breaks `withScope()` and Redis
   naming conventions.

## References

- PSR-6: Caching Interface — https://www.php-fig.org/psr/psr-6/
- PSR-16: Common Interface for Caching Libraries — https://www.php-fig.org/psr/psr-16/
- ARFA 1.3 — P4 (Protocol Agnosticism)
