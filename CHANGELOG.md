# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- Toolchain through KaririCode Devkit with the root configs (ADR-015): no
  `require-dev`; `devkit.php` pins PHPUnit 12.5.37, PHPStan 2.3.7 with
  phpstan-strict-rules 2.1.2 and cognitive-complexity 1.2.0, Psalm 6.19.1,
  PHP-CS-Fixer 3.95.27 and Infection 0.35.5, plus psr/cache and
  psr/simple-cache for the bridge tests. The scripts call kcode and
  `composer security` also audits the toolchain; APCu needs
  `apc.enable_cli=1` in `php.ini`.
- kariricode/contract comes from Packagist: the path fixture is removed and
  `composer.lock` is committed with contract 2.12.0 and psr/clock 1.0.0
  (ADR-013, Amendment).
- CI installs kcode 1.2.1 by version and SHA-256; Redis stays pinned by
  digest.

## [4.1.0] - 2026-10-02

First release that installs with Composer and whose test suite runs on a
real PHP 8.4 toolchain with real Redis and APCu backends. 4.0.0 required a
package version that does not exist, so none of its code had been installed
through Composer or executed by its own tests. Strict SemVer would call for
5.0.0 because of the breaking changes; 4.1.0 is used because no 4.0.x release
was installable. The release also aligns the package with KaririCode
Specification V4.3.3 and `kariricode/contract` 2.11 (ADR-013, ADR-014).

### Breaking

- Error codes moved to the package range 8600–8699 (see the upgrade table);
  the 4.0 codes collided with AsyncPrimitives, EventLoop and Async, and the
  8400 block of the 4.1.0 drafts with Authenticator (ADR-013).
- Exceptions follow `kariricode/contract` 2.11 (ADR-013): `CacheException` is
  a marker interface — catch it as before, but it can no longer be
  instantiated or extended; backend and payload failures are
  `BackendException` (8600–8605); an unknown registry store is
  `StoreNotFoundException` (8620); `InvalidConfigurationException` is
  renamed `ConfigurationException` (8640) and also covers missing extensions
  (8641). Each exception implements exactly one contract family.
- Exception messages no longer contain keys, rejected values or store names
  (Specification E-047); the cause stays in the previous exception.
- `ConnectionException::unavailable()` replaced by `connectionFailed()`
  (8610); the missing-extension case is
  `ConfigurationException::extensionMissing()` (8641).
- `BackendException::serializationFailed(string $reason, ?\Throwable)` and
  `decompressionFailed(?string $detail)` replace the `CacheException`
  factories; `readFailed()`, `writeFailed()` and `deleteFailed()` take only
  the previous exception.
- Runtime requirements: `kariricode/contract ^2.11` and `psr/clock ^1.0`,
  both interface packages (Specification §31). Until contract 2.11.0 is on
  Packagist it installs from `tools/contract-2.11-fixture` (path repository)
  and `composer.lock` is not committed.
- Time and randomness are injected (ADR-014): `ArrayAdapter`,
  `FilesystemAdapter`, `Cache`, `TagAwareCache`, `LayeredCache`,
  `Psr16Bridge` and `Psr6Bridge` take a PSR-20 clock; `XFetchPrevention`,
  `TagAwareCache` and `LayeredCache` take a `Random\Randomizer`.
- `CacheEntry` and `AdapterStats` are `final readonly` classes; derived
  values are query methods — `isExpiredAt($now)`, `secondsLeftAt($now)`,
  `ageAt($now)`, `totalRequests()`, `hitRatio()` — instead of hooks.
  `CacheEntry::withTtl()` takes `$now`.
- Specification V4 §13/§18 alignment:
  - `Manager\CacheManager` → `Registry\CacheRegistry` (`register()`,
    `store(?name)`, `has()`, `names()`, `clearAll()`); it no longer
    implements `Cache` by delegation;
  - `Eviction\EvictionPolicyFactory` removed → `EvictionStrategy::createPolicy(capacity)`;
  - `Cache` has no `maxItems` argument: capacity is the policy's
    `capacity` (`new SievePolicy(capacity: 1_000)`), default 10 000;
  - `RedisAdapter(RedisConnection $connection, string $prefix, PayloadCodec $codec)`:
    host, port, database, password and timeouts moved to
    `Configuration\RedisConnection`;
  - `FilesystemAdapter(string $directory, FilesystemOptions $options, PayloadCodec $codec, ClockInterface $clock)`:
    modes, sharding and GC settings moved to `Configuration\FilesystemOptions`;
  - serializer and compressor travel together in `Serialization\PayloadCodec`.
- `CacheWarmerRunner::warmAll()` returns `list<WarmupResult>` instead of
  associative arrays.
- TTL semantics unified (ADR-010): `null` = no expiry, `<= 0` deletes the
  entry and returns `true`, on every adapter and cache.
- `remove()`/`delete()` are idempotent: `true` for a missing key.
- Capacity `0` means unbounded (`new SievePolicy(capacity: 0)`); a negative
  capacity throws (8640).
- Storage formats: `TagAwareCache` stores `TaggedEntry`, `LayeredCache`
  stores `CacheEntry`, `FilesystemAdapter` files carry an expiry/key header.
  Entries written by 4.0 read as misses.
- PSR bridges reject `:` in keys and throw exceptions implementing the PSR
  interfaces (8650–8653).
- `TagAwareCache` rejects keys starting with `__tag_version:` (8632).
- `ScopedCache` rejects empty scopes and reserved characters (8640).
- `composer.json` declares ARFA 1.3 **Level 1** (was 2) under
  `extra.kariricode.arfa`, plus `extra.kariricode.asvs`, `spec` (4.3.3) and
  `error-code-range`.

### Fixed

- The package could not be installed: `require` listed
  `kariricode/contract: ^3.0`, which does not exist on Packagist (latest
  2.8.6), and no class from it was used.
- `RedisAdapter::flush()` with a prefix deleted nothing when SCAN returned an
  empty page first (30 of 30 keys left in a database with 20 000 other
  keys); SCAN now runs with `SCAN_RETRY` and an escaped pattern.
- `FilesystemAdapter` could not read entries without TTL (`isset()` on a
  null expiry); `flush()` deleted the whole directory tree recursively;
  `gcDivisor: 0` crashed on the first write; GC unserialized every file.
- A serializer with a class allowlist made `Cache` return
  `__PHP_Incomplete_Class` for every entry.
- `ApcuAdapter` stored `ttl: 0` forever; `RedisAdapter` failed on it.
- `Cache(maxItems: 0)` evicted every entry.
- `LfuPolicy` stopped evicting after the least-frequent key was removed.
- `FifoPolicy` and `ArrayAdapter` evicted a deleted-and-reinserted key as if
  it were the oldest; `FifoPolicy` cost O(n) per eviction; `ArrayAdapter`'s
  order list grew without bound in long-running workers.
- `SievePolicy` evicted the tail instead of the entry at the hand when every
  entry was visited (differs from the paper when the hand is mid-queue).
- `TagAwareCache`: a user array shaped like the internal envelope was
  unwrapped; invalidated entries became valid again when a tag key was lost;
  concurrent invalidations could cancel each other; `rememberStale()` used a
  `:__fresh` sidecar key.
- `LayeredCache`: promotion extended a value's life to the faster layer's
  TTL; jitter could lengthen an explicit TTL; `rememberStale()` ignored
  `$freshTtl`; `setMultiple()` with numeric keys raised a `TypeError`.
- PSR-6: deferred items were invisible before `commit()` and not committed on
  destruction; a cached `null` was a miss. PSR-16: invalid keys raised an
  exception that did not implement the PSR interface.
- `RedisAdapter::isAvailable()` always returned `false`; `\RedisException`
  leaked from every operation.
- `CacheManager` threw `CacheException` with code 0; `ZlibCompressor` and
  `IgbinarySerializer` threw generic `\RuntimeException`.
- `rememberStale()` accepted `freshTtl > staleTtl` silently.
- Recompute cost δ is measured with the monotonic `hrtime()`.
- `RedisConnection::open()` ignored a refused `SELECT`: phpredis reports it
  with `false`, so a database index the server does not have left the
  connection on database 0, shared with other clients. A refused `SELECT` or
  `AUTH` now raises `ConnectionException` (8610).
- `ApcuAdapter::stats()` always reported `memoryBytes: 0`: APCu returns
  `mem_size` as a float (5.1.28), which the integer check discarded.
- PSR-6 `expiresAfter()` counted from `save()`, so a deferred item committed
  later outlived the period it was given; it now counts from the call, as
  PSR-6 defines (ADR-014).
- PHPStan crashed on `apcu_store($values, ttl: …)`.
- Documentation claims corrected: reads do not return `CacheEntry`; there
  are no atomic operations; stale-while-revalidate is not single-flight with
  XFetch; SIEVE's 63.2% is "up to", versus ARC; the LFU reference is Shah,
  Mitra and Matani (2010).

### Added

- `ConfigurationException` (8640, 8641) with fail-fast validation of every
  constructor option; `InvalidKeyException::validate($key, psrStrict)` and
  `reservedPrefix()` (8632).
- `Exception\BackendException`, `Exception\StoreNotFoundException`.
- `Bridge\Exception\Psr16InvalidArgumentException`, `Psr16CacheException`,
  `Psr6InvalidArgumentException`, `Psr6CacheException` (8650–8653).
- `Provider\CacheServiceProvider` (contract 2.11 DI): binds the registry,
  the default store and its PSR-16/PSR-6 views as lazy singletons.
- `Clock\SystemClock` (PSR-20) and `Serialization\PayloadCodec`.
- `#[\Override]` on every method that implements or overrides another.
- Quality gate of Specification V4.3.3 §29.1: Psalm (error level 1),
  Infection (MSI and covered MSI ≥ 80 %), `tools/complexity.php` (§13
  budgets, §30 named arguments), cognitive complexity ≤ 15 in PHPStan,
  `tools/secret-scan.php`, `tools/sbom.php` (CycloneDX 1.5) and
  `composer check`; a GitHub Actions workflow pinned by commit SHA.
- `ValueObject\TaggedEntry`, `ValueObject\WarmupResult`.
- Injectable `StampedePrevention` in `TagAwareCache` and `LayeredCache`.
- `phpunit.xml.dist` (Unit, Integration, Conformance), `phpstan.neon`
  (level max + strict rules), `.php-cs-fixer.dist.php` (`@PER-CS3x0`),
  `LICENSE`, `CONTRIBUTING.md`, `.gitattributes`, `.gitignore`.
- `Configuration\RedisConnection`, `Configuration\FilesystemOptions`,
  `Registry\CacheRegistry`, `EvictionPolicy::$capacity`,
  `EvictionStrategy::createPolicy()`.
- Tests: 177 → 503, including Redis and APCu integration tests, PSR-6/PSR-16
  and ARFA conformance suites, `CodeBudgetConformanceTest` (Specification §13,
  §18, §29.2, §30), randomized policy invariants, deterministic clock and
  seeded-randomness tests, a `security` group (109 tests) and a regression
  test for every fix above. Line coverage measured for the first time: 95.5%; mutation score 88.4%.
- `SECURITY.md`: threat model, ASVS 5.0 level 1 scope, fail-closed
  behaviour and supply-chain controls.
- ADR-009 to ADR-014; ADR-001 to ADR-008 and SPEC-001 rewritten.
- `benchmark/eviction_benchmark.php` runs the real policy classes (results in
  SPEC-001 §10).

### Removed

- `Enum\AdapterType` (unused).
- `RedisAdapter::increment()/decrement()` and `ApcuAdapter::increment()/
  decrement()`: they bypassed the serializer; use
  `RedisAdapter::connection()` or APCu functions directly.
- `benchmark/validate_algorithms.py` (it validated a Python copy of the
  algorithms, not the PHP classes).
- `kariricode/contract ^3.0` (a version that does not exist; `^2.11` is now
  required, see Breaking) and `kariricode/devkit` (require-dev).
- `Exception\InvalidConfigurationException` (renamed `ConfigurationException`).

### Upgrading from 4.0.x

| 4.0.x | 4.1.0 |
|---|---|
| codes 1001–1005 | 8600–8604 (`BackendException::*_FAILED` constants) |
| code 2001 | 8610 `ConnectionException::connectionFailed()` / 8641 `ConfigurationException::extensionMissing()` |
| codes 3001 / 3002 | 8630 / 8631 |
| codes 84xx of the 4.1.0 drafts | 86xx in the same order, except 8406 → 8620 and 8421 → 8641 |
| `catch (CacheException $e)` | unchanged (now an interface) |
| `new CacheException(...)`, `extends CacheException` | throw a concrete exception; extend nothing |
| `InvalidConfigurationException` | `ConfigurationException` |
| `new RedisAdapter($c, 'app:', serializer: $s, compressor: $z)` | `new RedisAdapter($c, 'app:', new PayloadCodec($s, $z))` |
| `$entry->isExpired`, `$entry->ttlRemaining`, `$stats->hitRatio` | `$entry->isExpiredAt($now)`, `$entry->secondsLeftAt($now)`, `$stats->hitRatio()` |
| `EvictionPolicyFactory::create($s)` | `$s->createPolicy(capacity: 10_000)` |
| `new Cache($adapter, maxItems: 500, evictionPolicy: new LruPolicy())` | `new Cache($adapter, new LruPolicy(capacity: 500))` |
| `new RedisAdapter(host: 'h', password: 'p', prefix: 'app:')` | `new RedisAdapter(new RedisConnection(host: 'h', password: 'p'), 'app:')` |
| `new FilesystemAdapter($dir, gcDivisor: 500)` | `new FilesystemAdapter($dir, new FilesystemOptions(gcDivisor: 500))` |
| `$manager = new CacheManager(); $manager->addStore('a', $c); $manager->get('k')` | `$registry = (new CacheRegistry())->register('a', $c); $registry->store()->get('k')` |
| `$report[0]['elapsed_ms']` | `$results[0]->elapsedMs` (`WarmupResult`) |
| `if (!$adapter->remove($k))` to detect absence | `$adapter->exists($k)` |
| `$redisAdapter->increment($k)` | `$redisAdapter->connection()->incrBy($k, 1)` (raw key, no prefix) |
| keys with `:` through the PSR bridges | keys without `:`, or the native API |
| existing TagAwareCache / LayeredCache / Filesystem data | read as misses; warm or flush after deploying |

## [4.0.0] - 2026-03-01

ARFA 1.3 upgrade: SIEVE eviction, XFetch, stale-while-revalidate, tags,
layering, scoping, compression, events, warming and PSR bridges. Distributed
as source archives only; not installable with Composer (see 4.1.0).
