# ADR-001: SIEVE as the Default Eviction Policy

## Status

Accepted — V4.0.0 · Revised V4.1.0

## Date

2026-03-01 · revised 2026-09-30

## Context

`Cache` bounds the number of entries it tracks (the policy's `capacity`) and needs a
policy that picks the victim when the bound is reached. The policy runs on
every read and write, so its per-operation cost matters as much as its
miss ratio.

### Prior Art

- **LRU** — the usual baseline: O(1) with a hash map and a doubly linked
  list, but every hit moves an entry to the head of the list.
- **LFU** — O(1) with frequency buckets (Shah, Mitra, Matani, 2010); keeps
  stale popular keys after the popularity changes.
- **SIEVE** (Zhang, Yang, Yue, Vigfusson, Rashmi — NSDI 2024) — FIFO queue,
  one visited bit per entry and a moving hand. Hits only set a bit. On 1559
  traces from 7 sources the authors report up to 63.2% lower miss ratio
  than ARC, and a lower miss ratio than 9 state-of-the-art algorithms on
  more than 45% of the traces.
- Most PHP caches leave capacity to the backend (Redis `maxmemory-policy`,
  APCu shared-memory size) instead of an application-level policy.

## Decision

`SievePolicy` is the default policy of `Cache`. `LruPolicy`, `LfuPolicy`,
`FifoPolicy` and `NullEvictionPolicy` implement the same `EvictionPolicy`
contract; each carries its `capacity` (default 10 000, `0` = unbounded).
`EvictionStrategy::createPolicy(capacity)` builds one from configuration; it
replaced `EvictionPolicyFactory` in 4.1.0, a class that only wrapped `new`
(Specification V4 §18).

| Operation | SIEVE | LRU | LFU | FIFO |
|---|---|---|---|---|
| hit | set visited bit | move to head | move bucket | nothing |
| insert | push to head | push to head | bucket 1 | append |
| evict | hand scan, amortized O(1) | tail | first key of min bucket | first key |

### Revision 4.1.0

1. **SIEVE all-visited case.** When every entry was visited, 4.0 evicted the
   tail after one rotation. The paper keeps scanning and evicts the entry at
   the hand, whose bit was just cleared; with the hand mid-queue the two
   differ. The loop now runs n + 1 steps, as in the paper.
2. **LFU stopped evicting.** After the least-frequent key was removed, the
   minimum-frequency scan stopped at `count(keys)` and reported "nothing to
   evict" while keys were tracked. The minimum is now recomputed from the
   bucket keys.
3. **FIFO evicted re-inserted keys early and cost O(n).** It is now an
   insertion-ordered map (`array_key_first`, `unset`): O(1), and a key
   removed and inserted again becomes the newest.
4. **Numeric-string keys.** PHP turns `'123'` into an int array key; every
   policy casts keys back to string before returning them.
5. **Claims.** Docblocks said SIEVE had "63.2% lower miss ratio than ARC";
   the paper says *up to*. The LFU docblock cited O'Neil et al. (1993),
   which is LRU-K; the O(1) LFU scheme is Shah, Mitra and Matani (2010).
6. **Measured, not assumed.** `benchmark/eviction_benchmark.php` now runs the
   real policy classes with a fixed seed (results in SPEC-001 §11). The
   Python re-implementation used before was removed: it validated a second
   copy of the algorithms, which did not have the PHP bugs above.

Results with seed 42 (100 000 requests, 10 000 keys, miss ratio, SIEVE
relative to LRU):

| Workload | cache 100 | 500 | 1000 | 2000 |
|---|---|---|---|---|
| Zipf α=1.0 | −19.0% | −17.7% | −16.2% | −13.8% |
| Zipf α=0.8 | −13.5% | −14.5% | −14.1% | −12.3% |
| popularity reshuffled every 25k requests | −15.7% | +3.9% | +9.8% | +13.5% |
| Zipf with bursts of one-time keys | −14.7% | −14.8% | −17.3% | −17.9% |

On the stationary workloads LFU is within 1.4 percentage points of SIEVE;
under abrupt popularity shifts LFU is far worse and LRU adapts faster than
SIEVE at the larger sizes.

## Consequences

### Positive

- Hits cost one boolean write; no list update on the read path.
- Lower miss ratio than LRU on three of the four benchmark workloads.
- One contract, five interchangeable policies.

### Negative

- The policy lives in process memory. It bounds `ArrayAdapter` and
  long-running workers; under PHP-FPM with a shared backend each request
  sees only its own writes (ADR-010).
- Not universally better: LRU won on the reshuffled workload, and there is
  no explicit scan protection as in ARC or 2Q.
- Eviction may scan the queue (amortized O(1), worst case one rotation).

## Alternatives Considered

1. **LRU as default** — rejected: higher miss ratio on the stationary and
   scan workloads and a list update on every hit.
2. **ARC / 2Q / W-TinyLFU** — rejected for now: more state and tuning; the
   gain over SIEVE is workload-dependent.
3. **No application-level policy** — kept as `NullEvictionPolicy` for
   deployments that rely on the backend's own limits.

## References

- Y. Zhang et al., "SIEVE is Simpler than LRU", NSDI 2024 —
  https://www.usenix.org/conference/nsdi24/presentation/zhang-yazhuo
- K. Shah, A. Mitra, D. Matani, "An O(1) algorithm for implementing the LFU
  cache eviction scheme", 2010 — http://dhruvbird.com/lfu.pdf
- `benchmark/eviction_benchmark.php`
- ARFA 1.3 — P4 (Protocol Agnosticism)
