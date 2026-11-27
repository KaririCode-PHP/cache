# ADR-002: XFetch Probabilistic Early Recomputation

## Status

Accepted — V4.0.0 · Revised V4.1.0

## Date

2026-03-01 · revised 2026-09-30

## Context

When a popular entry expires, every concurrent request misses and runs the
same expensive computation (cache stampede). The protection must work in
share-nothing PHP, where workers cannot coordinate through memory.

### Prior Art

- **XFetch** (Vattani, Chierichetti, Lowenstein — VLDB 2015): recompute
  when `now − δ·β·ln(U) ≥ expiry`, U uniform in (0, 1]; optimal under the
  paper's model with β = 1.
- **Symfony Cache Contracts** (since 4.2): probabilistic early expiration
  in `CacheInterface::get()` with β defaulting to 1.0, plus locking for cold
  caches.
- **Locking / single-flight**: one caller recomputes, others wait or get
  the old value (ADR-008).

## Decision

`XFetchPrevention` is the default `StampedePrevention` of `Cache`,
`LayeredCache` and `TagAwareCache`. The recompute cost δ is measured around
the factory and stored with the value in the backend entry (ADR-009), so
every worker decides from the same `(expiry, δ)` without coordination.

P(recompute) at a distance `gap` before expiry is `exp(−gap / (δ·β))`.

### Revision 4.1.0

1. **Monotonic clock.** δ is measured with `hrtime()`; `microtime()` can jump
   with clock adjustments.
2. **β validated.** Zero, negative or non-finite β raises
   `ConfigurationException` (8640).
3. **Validation fixed.** The 4.0 benchmark compared observations with
   `1 − exp(−gap/δβ)` and still printed a check mark. The observed values
   were right; the reference was wrong. Against `exp(−gap/δβ)` the maximum
   absolute error over six gaps was between 0.0008 and 0.0035 in six runs
   (100 000 samples per gap), when the draw still used `random_int()`. The
   draw now comes from an injected `Random\Randomizer` (ADR-014): the secure
   engine by default, a seeded `Mt19937` in tests and in the benchmark, which
   makes the check reproducible: with seed 42 the maximum absolute error over
   the six gaps is 0.0024 (gap 0.5 s), and two runs print the same table
   (2026-10-02). The standard error of a proportion at 100 000 samples is at
   most 0.0016.
4. **After the logical expiry every caller recomputes.** XFetch only spreads
   recomputation *before* expiry; it is not single-flight afterwards. This
   is now stated in the `Cache` contract and in ADR-004.

## Consequences

### Positive

- No locks, no coordination, works across PHP-FPM workers and servers.
- Tunable with one parameter; β = 1 needs no tuning.

### Negative

- Probabilistic: with low traffic nobody may recompute early, and at expiry
  concurrent callers still recompute together.
- The caller selected to recompute pays the full cost synchronously.

## Alternatives Considered

1. **Locking only** — rejected as default: cannot coordinate PHP-FPM workers
   without a backend lock; available per process as `LockingPrevention`.
2. **Fixed early-refresh window** — rejected: every caller inside the window
   recomputes.

## References

- A. Vattani, F. Chierichetti, K. Lowenstein, "Optimal Probabilistic Cache
  Stampede Prevention", PVLDB 8(8), 2015 —
  https://www.vldb.org/pvldb/vol8/p886-vattani.pdf
- Symfony, "New in Symfony 4.2: Cache stampede protection" —
  https://symfony.com/blog/new-in-symfony-4-2-cache-stampede-protection
- ARFA 1.3 — P4 (Protocol Agnosticism)
