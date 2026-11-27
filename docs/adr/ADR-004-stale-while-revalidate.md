# ADR-004: Stale-While-Revalidate in a Request Model

## Status

Accepted — V4.0.0 · Revised V4.1.0

## Date

2026-03-01 · revised 2026-09-30

## Context

For data that tolerates a little staleness, serving the previous value while
a new one is computed removes latency spikes at expiry. HTTP caches do this
in the background (RFC 5861). A PHP-FPM request has no background thread:
whoever recomputes waits for the factory, unless work is deferred until
after the response is sent.

### Prior Art

- **RFC 5861** `stale-while-revalidate`: a cache may serve a stale response
  while it revalidates asynchronously.
- **Laravel `Cache::flexible()`**: fresh and stale periods; during the stale
  period the stale value is served and a deferred function refreshes the
  value after the response is sent.
- **XFetch** (ADR-002) and **locking** (ADR-008) decide who recomputes.

## Decision

`rememberStale($key, $factory, $freshTtl, $staleTtl)` stores one envelope
with two horizons:

| Horizon | Where | Purpose |
|---|---|---|
| fresh (logical) | `CacheEntry::$expiresAt` / `TaggedEntry::$expiresAt` | input of the stampede strategy |
| stale (physical) | adapter TTL | how long the value remains servable |

Who recomputes is the configured `StampedePrevention`:

| Strategy | Before the fresh horizon | After it (stale window) |
|---|---|---|
| `XFetchPrevention` (default) | early recomputation with P = exp(−gap/(δ·β)) | every caller recomputes until one stores the new value |
| `LockingPrevention` | never | one caller per key recomputes, the others get the stored value (one process) |

Recomputation is synchronous. `1 ≤ freshTtl ≤ staleTtl` is enforced
(`ConfigurationException`, 8640).

### Revision 4.1.0

1. **Documentation corrected.** 4.0 promised that during the stale window
   "one caller recomputes while all others receive the stale response". That
   holds only with `LockingPrevention`, inside one process. The claim that no
   PHP library implements stale-while-revalidate was false (Laravel does,
   with refresh after the response).
2. **TagAwareCache** kept a sidecar key `{key}:__fresh`, which could collide
   with user keys, and never recomputed early. It now stores one
   `TaggedEntry` and uses the injected strategy like `Cache`.
3. **LayeredCache** ignored `$freshTtl`; it now stores the logical horizon and
   consults its strategy.
4. **Window validation.** `freshTtl > staleTtl` used to be accepted silently.

## Consequences

### Positive

- Values stay servable after their fresh horizon; with enough traffic XFetch
  refreshes most entries before that point.
- Works on every adapter, because both horizons travel with the entry.

### Negative

- No refresh after the response: a request that recomputes pays the cost.
- With XFetch, requests that arrive after the fresh horizon and before the
  new value is stored all recompute.

## Alternatives Considered

1. **Deferred refresh after the response** (`fastcgi_finish_request()`,
   framework terminate events) — deferred: the package has no request
   lifecycle; a candidate for a future integration hook.
2. **Backend lock (`SET NX`) for single-flight across workers** — deferred:
   needs an atomic "add" capability in `CacheAdapter`.

## References

- RFC 5861, HTTP Cache-Control Extensions for Stale Content —
  https://www.rfc-editor.org/rfc/rfc5861
- Laravel Cache, Stale While Revalidate — https://laravel.com/docs/cache
- ARFA 1.3 — P4 (Protocol Agnosticism)
