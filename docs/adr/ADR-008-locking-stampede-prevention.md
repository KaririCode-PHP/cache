# ADR-008: Per-Key Locking Stampede Prevention

## Status

Accepted — V4.0.0 · Revised V4.1.0

## Date

2026-03-01 · revised 2026-09-30

## Context

XFetch (ADR-002) spreads recomputation before expiry but is not
single-flight: after the logical expiry every caller recomputes until one of
them stores the new value. Long-running workers (RoadRunner, Swoole,
FrankenPHP worker mode) can do better inside one process.

### Prior Art

- **Symfony Cache** — adds a lock-based protection for cold caches next to
  probabilistic early expiration.
- **Single-flight** (Go `singleflight`) — concurrent callers of one key share
  one computation.

## Decision

`LockingPrevention(lockDuration: 10.0)` implements `StampedePrevention`:

- before the logical expiry it never recomputes;
- at or after it, the first caller of a key acquires a per-key lock and
  recomputes; later callers get `false` (keep the stored value) until the
  lock expires.

It is effective with `rememberStale()`, where the value outlives its logical
expiry. With `remember()` the entry is physically gone at expiry, so the
miss path recomputes regardless of the lock.

### Revision 4.1.0

1. **Scope stated.** The lock lives in process memory: it coordinates the
   callers of one worker, not the workers of a PHP-FPM pool. 4.0 did not say
   so.
2. **Validation.** `lockDuration` must be finite and > 0
   (`ConfigurationException`, 8640).
3. **Expired locks pruned before acquiring**, so the lock table does not
   grow with keys that are no longer requested.

## Consequences

### Positive

- True single-flight after the fresh horizon, inside one process.
- No backend round trip.

### Negative

- No early recomputation: the first caller after the horizon pays the full
  cost.
- A lock is not released when the recompute finishes; it expires after
  `lockDuration`.
- No coordination across processes or servers.

## Alternatives Considered

1. **Backend lock (`SET key token NX PX`)** — deferred: needs an atomic
   "add" capability in `CacheAdapter` (Redis `SET NX`, APCu `apcu_add`,
   exclusive file create); a candidate for a future minor release.
2. **Blocking waiters** — rejected: in PHP-FPM a waiting worker is a worker
   not serving other requests.

## References

- Symfony, "New in Symfony 4.2: Cache stampede protection" —
  https://symfony.com/blog/new-in-symfony-4-2-cache-stampede-protection
- M. Kleppmann, "How to do distributed locking" —
  https://martin.kleppmann.com/2016/02/08/how-to-do-distributed-locking.html
- ARFA 1.3 — P4 (Protocol Agnosticism)
