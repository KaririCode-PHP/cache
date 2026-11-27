# ADR-014: Injectable Time and Randomness

## Status

Accepted — V4.1.0

## Date

2026-10-02

## Context

Specification V4.3.3 §19.2 requires time and randomness to be injectable.
The 4.1.0 drafts read the wall clock directly (`microtime(true)` in the
memory and file adapters, in `Cache`, `TagAwareCache`, `LayeredCache` and
the `CacheEntry` hooks; `time()` and `new DateTimeImmutable()` in the PSR
bridges) and drew with `random_int()` (XFetch, tag versions, layer TTL
jitter). As a result, expiry tests had to sleep, XFetch and tag versions
could not be replayed, the benchmark's XFetch table changed on every run,
and the `CacheEntry` hooks returned different values for the same object.

## Decision

1. **Time through PSR-20.** `Psr\Clock\ClockInterface` is injected into
   `ArrayAdapter`, `FilesystemAdapter`, `Cache`, `TagAwareCache`,
   `LayeredCache`, `Psr16Bridge` and `Psr6Bridge` (which passes it to its
   items); `SystemClock` is the default. Instants become float seconds with
   microseconds (`U.u`).
2. **Randomness through `Random\Randomizer`.** Injected into
   `XFetchPrevention`, `TagAwareCache` and `LayeredCache`; the default engine
   is the secure one. XFetch draws U from (0, 1] with
   `getFloat(min: 0.0, max: 1.0, boundary: IntervalBoundary::OpenClosed)`, so
   `log(U)` is never `log(0)`.
3. **Pure derived values.** `CacheEntry` exposes `isExpiredAt($now)`,
   `secondsLeftAt($now)` and `ageAt($now)` instead of time-dependent hooks
   (E-040).
4. **PSR-6 deadlines count from the call.** `Psr6CacheItem::expiresAfter()`
   stores an absolute expiration computed with the injected clock; PSR-6
   defines the period as starting from the present. Previously the relative
   TTL was resolved at save time, so a deferred item committed later lived
   longer than asked.
5. **Documented exceptions.**
   - δ, the recompute cost, is a duration and is measured with `hrtime()`:
     monotonic, while a wall clock (PSR-20) can jump.
   - The filesystem GC sampling (`random_int()`) and temporary file names
     (`random_bytes()`) change when work happens, never a result; tests make
     GC deterministic with `gcProbability: 0` or equal to `gcDivisor`.

## Consequences

### Positive

- Expiry is tested without sleeping (`FrozenClock`); XFetch decisions and
  tag versions replay from a seed (`InjectedTimeAndRandomnessTest`); the
  benchmark's XFetch table is reproducible.
- No global state: two caches can run on different clocks or engines.

### Negative

- `psr/clock` joins the runtime requirements (an interface package).
- Constructors gained parameters. `Cache` and `TagAwareCache` became
  readonly classes with promoted parameters (exempt from the parameter
  budget); `FilesystemAdapter` stays at four through `PayloadCodec`, which
  replaces its serializer and compressor parameters.
- Two clocks coexist by design: the wall clock for expiry, the monotonic one
  for δ.

## Alternatives Considered

1. **A package-specific clock interface** — rejected: PSR-20 exists, and the
   contract dropped its own clock in favour of it.
2. **`mt_srand()` seeding** — rejected: global state, not injectable per
   instance.
3. **An injected monotonic clock for δ** — rejected: no standard interface
   exists, and `hrtime()` only measures a duration.

## References

- PSR-20, Clock — https://www.php-fig.org/psr/psr-20/
- PHP, `Random\Randomizer` — https://www.php.net/manual/en/class.random-randomizer.php
- PHP, `Random\Randomizer::getFloat()` — https://www.php.net/manual/en/random-randomizer.getfloat.php
- PSR-6, `CacheItemInterface::expiresAfter()` — https://www.php-fig.org/psr/psr-6/
- KaririCode Specification V4.3.3 §19.2
