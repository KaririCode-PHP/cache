# SPEC-001: KaririCode Cache

## Version

4.1.0 — 2026-10-02 (supersedes the 4.0.0 text of 2026-03-01); conforms to
KaririCode Specification V4.3.3 and kariricode/contract 2.11

## Abstract

Cache library for PHP 8.4+: five storage adapters behind one port, a
primary `Cache` with an application-level eviction policy (SIEVE by
default), probabilistic early recomputation (XFetch), stale-while-revalidate
adapted to a request model, tag invalidation, multi-tier layering, scoping,
observation hooks and PSR-6/PSR-16 bridges. Runtime dependencies are
interface packages only: kariricode/contract (exception families, DI
provider) and psr/clock (Spec V4 §31).

---

## 1. Scope

**In scope:** key/value caching of PHP values; TTL expiry; capacity
bounding in process memory; stampede prevention; tag invalidation; layering;
namespace scoping; cache warming; PSR-6/PSR-16 interoperability.

**Out of scope:** distributed locks and single-flight across processes
(ADR-008), background refresh after the response (ADR-004), HTTP caching,
and telemetry exporters (hooks only, ADR-007).

---

## 2. Conformance

### 2.1 ARFA 1.3 — Level 1 (structural)

Declared in `composer.json` as `extra.kariricode.arfa = {version: "1.3",
level: 1}`. Level 2 requires P2 over `Promise<Result>` and P3 as a MAPE-K
loop in a persistent runtime; this package offers neither, so 4.0's
"Level 2" declaration was withdrawn.

| Principle | Status | Implementation |
|---|---|---|
| P1 Immutable State Transformation | applied | `CacheEntry` (Value Object), `TaggedEntry`/`WarmupResult` (DPO), readonly services |
| P2 Reactive Flow Composition | not claimed | synchronous API |
| P3 Adaptive Context Awareness | not claimed | XFetch adapts per entry but is not a MAPE-K loop |
| P4 Protocol Agnosticism | applied | `CacheAdapter` port (hexagonal); PSR types confined to `Bridge/` |
| P5 Continuous Observability | hooks | `CacheEventListener` on every outcome, `AdapterStats`; signals are emitted by the listener implementation |

### 2.2 Security (OWASP ASVS 5.0 — declared level 1)

| Concern | Control |
|---|---|
| Deserialization of stored data | `PhpSerializer(allowedClasses: [...])` allowlist (package envelopes always admitted); corrupt payloads raise 8604 and become misses. The PHP manual warns that no option makes `unserialize()` safe for untrusted input: only point the cache at backends the application alone can write to. |
| File permissions | `FilesystemAdapter` modes validated (0–07777), atomic writes (temporary file + rename), `flush()`/`gc()` touch only files named by the adapter |
| Secrets | Redis password marked `#[\SensitiveParameter]` |

### 2.3 PSR-6 / PSR-16

Through `Psr6Bridge` and `Psr16Bridge` (ADR-011). Verified by
`tests/Conformance/Psr6ConformanceTest.php` and `Psr16ConformanceTest.php`.

| Requirement | Result |
|---|---|
| Reserved characters `{}()/\@:` rejected with the PSR `InvalidArgumentException` | yes (8650 / 8652) |
| Other failures implement the PSR `CacheException` | yes (8651 / 8653) |
| TTL ≤ 0 (int or `DateInterval`) deletes | yes |
| `delete()` of a missing key returns `true` | yes |
| Cached `null` is a hit / is returned instead of the default | yes |
| PSR-6 deferred items visible before `commit()`, persisted on destruction | yes |
| Expired items are misses | yes |

### 2.4 Specification V4.3.3 rules beyond ARFA

| Rule | Status |
|---|---|
| §13 budgets | `composer complexity` (`tools/complexity.php`): cyclomatic ≤ 10 (a `switch`/`match` is one decision; closures count toward their method), nesting ≤ 3, parameters ≤ 4 except constructors of readonly classes whose parameters are all promoted; cognitive ≤ 15 through PHPStan (`tomasvotruba/cognitive-complexity`). The soft budget of 10 public methods is reported, never failing; it is exceeded only by classes implementing the two 11-method contracts (`Cache`, `CacheAdapter`), up to 13 with their own extras |
| §17 docblocks C1–C5 | every type: role, ARFA and Spec lines, `Preconditions:`, `Thread-safety:`, `Side effects:`, `Example:`, `References:`, `@see`; no `@package`, `@author`, `@license`, `@since` — enforced by `ArfaConformanceTest` |
| §18 banned names | fixed in 4.1.0: `CacheManager` → `CacheRegistry`, `EvictionPolicyFactory` → `EvictionStrategy::createPolicy()` |
| §19.1 test layers | tests touching Redis, APCu or the filesystem live in `tests/Integration` |
| §19.2 injectable time and randomness | PSR-20 clock and `Random\Randomizer` injected (ADR-014); documented exceptions: δ measured with `hrtime()`, filesystem GC sampling and temporary file names |
| §19.4 mutation testing | Infection, MSI and covered MSI ≥ 80 % (result in §13) |
| §24 security gate | `composer security`; threat model and ASVS scope in `SECURITY.md` |
| §29.1 gate commands | `phpstan`, `psalm`, `complexity`, `test`, `test-integration`, `test-conformance`, `infection`, `security`, `sbom`, `check` in `composer.json`; CI runs `check`, `test-integration`, `infection`, `sbom` |
| §30 named arguments | method, static and constructor calls with three or more arguments use names — 0 positional calls, checked by `tools/complexity.php` |
| §31 coupling | runtime `require`: `php`, `kariricode/contract`, `psr/clock` — checked by `ArfaConformanceTest` |
| §32 supply chain | actions pinned by commit SHA, service image by digest, `persist-credentials: false`; CycloneDX 1.5 SBOM; `composer.lock` not committed while the contract comes from a path repository; signed releases at release time |
| E-040 value objects | derived values are query methods (`CacheEntry::isExpiredAt()`, `AdapterStats::hitRatio()`); hooks only on non-readonly classes (`NullEvictionPolicy::$capacity`) |
| E-047 messages | exception messages never echo keys, rejected values or store names — `ExceptionTest::testMessagesNeverEchoARejectedValue` (group `security`) |

### 2.5 Formatting

PER Coding Style. Spec E-045 pins 3.1; php-cs-fixer's newest set is 3.0
(`@PER-CS3x0` in `.php-cs-fixer.dist.php`, as in the sibling packages),
which already applies the E-046 corrections in code (`fn(` without a space).
Plus `declare(strict_types=1)`, alphabetical imports and no unused imports.

---

## 3. Package Inventory (60 source files)

| Directory | Files |
|---|---|
| (root) | Cache |
| `Adapter/` | ArrayAdapter, ApcuAdapter, FilesystemAdapter, NullAdapter, RedisAdapter |
| `Bridge/` | Psr16Bridge, Psr6Bridge, Psr6CacheItem |
| `Bridge/Exception/` | Psr16CacheException, Psr16InvalidArgumentException, Psr6CacheException, Psr6InvalidArgumentException |
| `Clock/` | SystemClock |
| `Compression/` | NullCompressor, ZlibCompressor |
| `Configuration/` | FilesystemOptions, RedisConnection |
| `Contract/` | Cache, CacheAdapter, CacheEventListener, CacheWarmer, Compressor, EvictionPolicy, LayeredCache, Serializer, StampedePrevention, TagAwareCache |
| `Enum/` | EvictionStrategy |
| `Event/` | NullEventListener |
| `Eviction/` | FifoPolicy, LfuPolicy, LruPolicy, NullEvictionPolicy, SievePolicy |
| `Eviction/Node/` | LruNode, SieveNode |
| `Exception/` | CacheException (marker interface), BackendException, ConfigurationException, ConnectionException, InvalidKeyException, StoreNotFoundException |
| `Layered/` | LayeredCache |
| `Provider/` | CacheServiceProvider |
| `Registry/` | CacheRegistry |
| `Scope/` | ScopedCache |
| `Serialization/` | IgbinarySerializer, PayloadCodec, PhpSerializer |
| `Stampede/` | LockingPrevention, NullPrevention, XFetchPrevention |
| `Tag/` | TagAwareCache |
| `ValueObject/` | AdapterStats, CacheEntry, EvictionResult, TaggedEntry, WarmupResult |
| `Warming/` | CacheWarmerRunner |

## 4. Type Taxonomy (Spec V4)

| Kind | Classes |
|---|---|
| Value Object — `final readonly class`, derived values as query methods (E-040) | CacheEntry, AdapterStats, EvictionResult |
| DPO — `final readonly class`, no behaviour | TaggedEntry, WarmupResult, FilesystemOptions (self-validating) |
| Entity — `final class`, mutable state behind methods | LayeredCache, CacheRegistry, CacheWarmerRunner, Psr6Bridge, Psr6CacheItem, ArrayAdapter, ApcuAdapter, RedisAdapter, FilesystemAdapter, SievePolicy, LruPolicy, LfuPolicy, FifoPolicy, LockingPrevention, SieveNode, LruNode |
| Stateless service — `final readonly class` | Cache, TagAwareCache, ScopedCache, Psr16Bridge, PayloadCodec, PhpSerializer, IgbinarySerializer, ZlibCompressor, NullCompressor, XFetchPrevention, NullPrevention, NullEventListener, SystemClock, CacheServiceProvider, RedisConnection (configuration with one behaviour, `open()`) |
| Stateless service — `final class` | NullAdapter, NullEvictionPolicy (property hook for `capacity`, allowed on non-readonly classes) |
| Contract | the ten interfaces in `Contract/` |
| Pure enum (E-003) | EvictionStrategy |
| Exception | marker interface `CacheException` (extends the contract's `ExceptionInterface`) and nine final classes, each extending an SPL exception and implementing exactly one contract family |

`Cache` and `TagAwareCache` hold collaborators only; the mutable state lives
in the policy, the adapter and the listener they receive.

No `toArray()`, `fromArray()`, `jsonSerialize()` or `get*()` getters, except
the PSR-6 methods mandated by an external contract (`Psr6CacheItem`).

---

## 5. Contracts

| Contract | Methods |
|---|---|
| `Cache` | `get`, `set`, `delete`, `has`, `clear`, `getMultiple`, `setMultiple`, `deleteMultiple`, `remember`, `rememberStale`, `withScope` |
| `TagAwareCache` extends `Cache` | `setTagged`, `invalidateTags` |
| `LayeredCache` extends `Cache` | `addLayer`, `layerCount` |
| `CacheAdapter` | `read`, `write`, `remove`, `exists`, `flush`, `readMultiple`, `writeMultiple`, `removeMultiple`, `stats`, `name`, `isAvailable` |
| `EvictionPolicy` | property `$capacity`; `recordAccess`, `recordInsertion`, `recordRemoval`, `evict`, `count`, `clear`, `name` |
| `StampedePrevention` | `shouldRecompute(key, now, expiry, delta)`, `name` |
| `Serializer` / `Compressor` | `serialize`/`unserialize`, `compress`/`decompress` |
| `CacheEventListener` | `onHit`, `onMiss`, `onWrite`, `onDelete`, `onEviction`, `onStampedeRecompute` |
| `CacheWarmer` | `warm`, `isOptional`, `name` |

---

## 6. Common Semantics

| Aspect | Rule |
|---|---|
| Keys (native) | non-empty, none of `{}()/\@` (8630, 8631); `:` allowed |
| Keys (bridges) | also reject `:` (PSR set) |
| TTL | `null` = no expiry; `> 0` seconds; `<= 0` deletes and the write returns `true` (ADR-010) |
| Delete | idempotent: `true` unless the backend refused |
| Envelope | `Cache`/`LayeredCache` store `CacheEntry`, `TagAwareCache` stores `TaggedEntry` (ADR-009) |
| Cached `null` | a hit for all three implementations |
| Unreadable entry | deleted and reported as a miss |
| Capacity | the eviction policy's `capacity` (default 10 000, `0` = unbounded), tracked in process memory (ADR-010) |

---

## 7. Adapters

| Adapter | Storage | Serialization | `flush()` scope | Failures |
|---|---|---|---|---|
| `ArrayAdapter(maxItems)` | process memory, FIFO overflow | none (by reference) | all entries | — |
| `ApcuAdapter(prefix)` | APCu shared memory | APCu | prefix; whole segment when no prefix | 8641 if APCu unusable |
| `RedisAdapter(RedisConnection, prefix, serializer, compressor)` | Redis | Serializer + Compressor | prefix via `SCAN` (`SCAN_RETRY`, escaped pattern); `FLUSHDB` when no prefix | 8610 connect, 8600–8603 operations |
| `FilesystemAdapter(directory, FilesystemOptions, serializer, compressor)` — options: modes, shardingLevels 0–8, gcProbability, gcDivisor ≥ 1 | one file per key: expiry line, key line, payload | Serializer + Compressor | own files and shard directories only | 8601 write, 8640 options |
| `NullAdapter` | nothing | — | — | — |

`FilesystemAdapter` reads only the two header lines in `exists()` and
`gc()`; a key-line mismatch (hash collision) is a miss. GC runs on writes
with probability `gcProbability/gcDivisor` (default 1/1000).

---

## 8. Composition

| Class | Behaviour |
|---|---|
| `TagAwareCache(adapter, stampede)` | random tag versions, versions captured before computing, missing version = invalid, reserved prefix `__tag_version:` (ADR-012) |
| `LayeredCache(stampede)` | read fastest-first, write-through, promotion TTL = min(layer TTL, time left), downward-only layer jitter ≤ 10%, explicit TTLs written unchanged |
| `ScopedCache(inner, scope)` | `"{scope}:{key}"`, nestable; `clear()` clears the inner cache (ADR-006) |
| `CacheRegistry(defaultStore)` | named stores: `register()`, `store(?name)` (null = default), `has()`, `names()`, `clearAll()`; unknown store → 8620 |
| `CacheWarmerRunner(cache, warmers)` | runs warmers in order, returns `list<WarmupResult>`; failures are recorded, not thrown |

---

## 9. Stampede Prevention and Stale-While-Revalidate

| Strategy | Before the logical expiry | After it |
|---|---|---|
| `XFetchPrevention(beta = 1.0)` (default) | recompute with P = exp(−gap/(δ·β)) | every caller recomputes until one stores |
| `LockingPrevention(lockDuration = 10.0)` | never | first caller per key, per process |
| `NullPrevention` | never | every caller recomputes |

δ is measured with `hrtime()` around the factory and stored in the envelope.
`rememberStale()` enforces `1 <= freshTtl <= staleTtl` (8640); the fresh
horizon is the logical expiry and the stale horizon the adapter TTL
(ADR-004).

---

## 10. Eviction

Policies: `SievePolicy` (default), `LruPolicy`, `LfuPolicy`, `FifoPolicy`,
`NullEvictionPolicy`; each carries its `capacity`.
`EvictionStrategy::createPolicy(capacity)` builds one from configuration.

Measured with `php benchmark/eviction_benchmark.php` (PHP 8.4.26, 100 000
requests, 10 000 keys, seed 42; seeds 7 and 2026 differ by less than 1.5
percentage points). Miss ratio of SIEVE relative to LRU:

| Workload | cache 100 | 500 | 1000 | 2000 |
|---|---|---|---|---|
| Zipf α = 1.0 | −19.0% | −17.7% | −16.2% | −13.8% |
| Zipf α = 0.8 | −13.5% | −14.5% | −14.1% | −12.3% |
| popularity reshuffled every 25 000 requests | −15.7% | +3.9% | +9.8% | +13.5% |
| Zipf with bursts of 1 000 one-time keys | −14.7% | −14.8% | −17.3% | −17.9% |

LFU is within 1.4 points of SIEVE on the stationary workloads and far worse
when popularity shifts. Single-thread throughput on the test machine (one
vCPU, 500 000 operations, 70% hits): SIEVE 2.9 M ops/s, LRU 1.7 M,
LFU 2.1 M, FIFO 3.9 M — machine-dependent, for comparison only.

---

## 11. Error Model

| Code | Exception · constant | Family | Factory | Raised when |
|---|---|---|---|---|
| 8600 | `BackendException::READ_FAILED` | Runtime | `readFailed()` | backend read failed (previous kept) |
| 8601 | `BackendException::WRITE_FAILED` | Runtime | `writeFailed()` | backend write failed |
| 8602 | `BackendException::DELETE_FAILED` | Runtime | `deleteFailed()` | backend delete failed |
| 8603 | `BackendException::FLUSH_FAILED` | Runtime | `flushFailed()` | backend flush failed |
| 8604 | `BackendException::SERIALIZATION_FAILED` | Runtime | `serializationFailed()` | payload cannot be (un)serialized |
| 8605 | `BackendException::DECOMPRESSION_FAILED` | Runtime | `decompressionFailed()` | marked-compressed payload is corrupt |
| 8610 | `ConnectionException::CONNECTION_FAILED` | Runtime | `connectionFailed()` | cannot connect to the backend |
| 8620 | `StoreNotFoundException::STORE_NOT_REGISTERED` | NotFound | `storeNotRegistered()` | unknown `CacheRegistry` store |
| 8630 | `InvalidKeyException::EMPTY_KEY` | Validation | `empty()` | empty key |
| 8631 | `InvalidKeyException::RESERVED_CHARACTERS` | Validation | `reservedCharacters()` | reserved character in the key |
| 8632 | `InvalidKeyException::RESERVED_PREFIX` | Validation | `reservedPrefix()` | key uses TagAwareCache's internal prefix |
| 8640 | `ConfigurationException::INVALID_OPTION` | Configuration | `invalidOption()`, `validateStaleWindow()` | impossible option or argument |
| 8641 | `ConfigurationException::EXTENSION_MISSING` | Configuration | `extensionMissing()` | required extension missing or disabled |
| 8650 | `Psr16InvalidArgumentException::INVALID_KEY` | Validation | `fromInvalidKey()` | PSR-16 key rule violated |
| 8651 | `Psr16CacheException::BACKEND_FAILURE` | Runtime | `fromCacheFailure()` | failure behind the PSR-16 bridge |
| 8652 | `Psr6InvalidArgumentException::INVALID_KEY` | Validation | `fromInvalidKey()` | PSR-6 key rule violated |
| 8653 | `Psr6CacheException::BACKEND_FAILURE` | Runtime | `fromCacheFailure()` | failure behind the PSR-6 bridge |

Sub-ranges: 8600–8609 backend, 8610–8619 connection, 8620–8629 lookup,
8630–8639 keys, 8640–8649 configuration, 8650–8659 PSR boundary; 8660–8699
reserved. Every exception implements the marker `CacheException` and exactly
one family of kariricode/contract 2.11 (ADR-013). Messages state the rule or
the option, never the key, the rejected value or the store name (E-047).

**Retired:** the 4.0 codes 1001–1005, 2001, 3001–3002 (collided with
AsyncPrimitives, EventLoop and Async), and the 8400–8499 block of the 4.1.0
drafts (kariricode/authenticator registered 8400–8599).

Ecosystem allocation as known on 2026-10-02: AsyncPrimitives 1000–1499,
EventLoop 2000–2499, Async 3000–3049, DependencyInjection 3100–3169, Queue
4000–4099, DataFlow 5000–5199, Database 6000–6199, Template 7000–7099,
CircuitBreaker 7100–7199, Authorization 7300–7499, EventDispatcher
8100–8199, Observability 8200–8249, RateLimiter 8300–8399, Authenticator
8400–8599, **Cache 8600–8699**, Console 9000–9099, I18n 9100–9199, Tenancy
9200–9299. Open collisions between other packages (Runtime × I18n, CLI ×
Tenancy, DataFlow's two maps) are tracked outside this package; the registry
belongs in kariricode/contract or in the Specification.

---

## 12. Language Features Used (PHP >= 8.4 required)

| Feature | Since | Where |
|---|---|---|
| Interface properties and property hooks | 8.4 | `EvictionPolicy::$capacity { get; }`, `NullEvictionPolicy` |
| `#[\Override]` | 8.3 | every method that implements or overrides one (188) |
| `Random\Randomizer`, `IntervalBoundary` | 8.2, 8.3 | XFetch draws, tag versions, layer jitter |
| Typed class constants | 8.3 | error codes, internal constants |
| `readonly` classes | 8.2 | value objects, DPOs and stateless services |
| `#[\SensitiveParameter]` | 8.2 | Redis password |
| First-class callable syntax (`strval(...)`) | 8.1 | adapters, `CacheRegistry` |
| `throw` as an expression (`?? throw`) | 8.0 | `CacheRegistry::store()` |
| Pure enums | 8.1 | `EvictionStrategy` |
| `new` in initialisers | 8.1 | default collaborators (`SystemClock`, `Randomizer`, `PayloadCodec`) |
| Explicit octal literals (`0o755`) | 8.1 | `FilesystemAdapter` |
| Constructor promotion, `match`, named arguments | 8.0 | throughout |

---

## 13. Quality Gates

Measured on 2026-10-02 with PHP 8.4.26, Redis 7.0.15 and APCu, 1 vCPU.

| Gate | Command | Result |
|---|---|---|
| Whole gate | `composer check` | passes: cs-check, phpstan, psalm, complexity, test, test-conformance, security |
| Tests | Unit + Integration + Conformance | 503 tests, 29 689 assertions, all passing; Redis and APCu exercised, nothing skipped |
| Static analysis | `composer phpstan` (level max, phpstan-strict-rules, cognitive ≤ 15; `src/` and `tools/complexity.php`) | 0 errors, no baseline, no inline ignores |
| Static analysis | `composer psalm` (error level 1) | 0 errors; types inferred for 97.7 %; exemptions in `psalm.xml`: `MixedAssignment`/`MixedReturnStatement` (a cached value is `mixed` by nature), and `RedundantCondition`/`TypeDoesNotContainType` in `RedisAdapter` only (Psalm's phpredis stubs omit the `false` and `Redis` returns) |
| Complexity (§13, §30) | `composer complexity` | 376 methods within budgets; 0 calls with three or more positional arguments |
| Code style | `composer cs-check` (`@PER-CS3x0`) | 0 of 124 files to fix |
| Security | `composer security` | manifest valid; `composer audit`: no advisories; secret scan clean (8 patterns, self-test passing); `security` group: 109 tests passing |
| Coverage | `composer test-coverage` (pcov) | lines 95.5 % (1142 of 1196), methods 87.4 % |
| Mutation (§19.4) | `composer infection` (Unit + Integration) | MSI and covered MSI 88.4 % (1 444 mutants: 1 274 killed, 2 time-outs, 168 survivors; minimum 80 %, no mutator or file excluded); 66 survivors are equivalent by construction (QD-V433 report), the rest are listed in `infection.log` |
| Regression power | the 4.1.0 suite run against the 4.0.0 sources (2026-09-30) | 64 assertion failures and 82 errors; most errors are API differences, the failures are behaviour the old code got wrong |

## 14. ADR Index

| ADR | Title |
|---|---|
| [001](../adr/ADR-001-sieve-eviction-policy.md) | SIEVE as the Default Eviction Policy |
| [002](../adr/ADR-002-xfetch-stampede-prevention.md) | XFetch Probabilistic Early Recomputation |
| [003](../adr/ADR-003-zero-third-party-dependencies.md) | Zero Third-Party Runtime Dependencies (amended by ADR-013) |
| [004](../adr/ADR-004-stale-while-revalidate.md) | Stale-While-Revalidate in a Request Model |
| [005](../adr/ADR-005-composable-compression.md) | Composable Compression |
| [006](../adr/ADR-006-namespace-scoping.md) | Namespace Scoping |
| [007](../adr/ADR-007-observability-events.md) | Observability Hooks |
| [008](../adr/ADR-008-locking-stampede-prevention.md) | Per-Key Locking Stampede Prevention |
| [009](../adr/ADR-009-backend-persisted-envelope.md) | Backend-Persisted Entry Envelope |
| [010](../adr/ADR-010-ttl-and-capacity-semantics.md) | TTL and Capacity Semantics |
| [011](../adr/ADR-011-psr-bridge-boundary.md) | PSR-6 / PSR-16 Bridge Boundary |
| [012](../adr/ADR-012-versioned-tag-invalidation.md) | Tag Invalidation with Random Versions |
| [013](../adr/ADR-013-contract-2.11-alignment.md) | Alignment with kariricode/contract 2.11 |
| [014](../adr/ADR-014-injectable-time-and-randomness.md) | Injectable Time and Randomness |
