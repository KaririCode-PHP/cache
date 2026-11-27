# ADR-007: Observability Hooks

## Status

Accepted — V4.0.0 · Revised V4.1.0

## Date

2026-03-01 · revised 2026-09-30

## Context

Hit ratio, eviction rate and stampede recomputations are the numbers that
tell whether a cache helps. ARFA 1.3 P5 asks every operation to be
observable (logs, metrics, traces, profiles), but a cache must not depend on
a particular telemetry stack.

### Prior Art

- **Laravel** — dispatches cache events (hit, missed, written, forgotten)
  through its event dispatcher.
- **Symfony Cache** — `TraceableAdapter` records calls for the profiler.

## Decision

`Cache` calls a `CacheEventListener` synchronously on every outcome:

| Hook | Fired when |
|---|---|
| `onHit($key)` | `get()`/`remember()`/`rememberStale()` return a stored value |
| `onMiss($key)` | the key is absent (also drops the key from the eviction policy) |
| `onWrite($key, $ttl)` | a value is stored |
| `onDelete($key)` | `delete()` is called |
| `onEviction($key, $policy)` | the policy evicts to respect its `capacity` |
| `onStampedeRecompute($key, $gap)` | the stampede strategy chose to recompute a stored entry |

`NullEventListener` is the default. Metrics, traces and logs are emitted by
the listener implementation (for example a bridge to
kariricode/observability), not by this package.

### Revision 4.1.0

1. **Scope stated.** Only `Cache` fires hooks. `TagAwareCache` and
   `LayeredCache` do not in 4.1.0; `ScopedCache` delegates to its inner
   cache, so hooks see the scoped key.
2. **Misses clean the policy.** `onMiss` now coincides with removing the key
   from the eviction policy, so entries that expired in the backend no
   longer occupy tracked capacity (ADR-010).
3. **Error behaviour stated.** Listeners run on the hot path and are not
   wrapped: an exception thrown by a listener reaches the caller.

## Consequences

### Positive

- No telemetry dependency; any backend can be wired through one interface.
- Hooks carry the key, so per-prefix metrics are possible.

### Negative

- Synchronous: a slow listener slows every cache call.
- Key labels can have unbounded cardinality; aggregate by prefix before
  exporting.
- `TagAwareCache` and `LayeredCache` are not instrumented yet.

## Alternatives Considered

1. **PSR-14 events** — rejected: an event object per cache call costs more
   than a method call, and it would add a dependency.
2. **Counters inside `Cache`** — rejected: `AdapterStats` already exposes
   per-adapter counters; the listener exists for richer signals.

## References

- Laravel cache events — https://laravel.com/docs/cache#events
- Symfony Cache, `TraceableAdapter` — https://symfony.com/doc/current/components/cache.html
- OpenTelemetry metrics data model — https://opentelemetry.io/docs/specs/otel/metrics/data-model/
- ARFA 1.3 — P5 (Continuous Observability)
