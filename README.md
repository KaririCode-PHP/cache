# KaririCode\Cache

## 1. Overview

Cache library for PHP 8.4+. One `CacheAdapter` port with five adapters
(memory, APCu, Redis, filesystem, null) and, on top of it:

- `Cache` — capacity policy (SIEVE by default), XFetch probabilistic early
  recomputation, stale-while-revalidate and observation hooks;
- `TagAwareCache` — invalidation by tags with random versions;
- `LayeredCache` — multi-tier read-through / write-through;
- `ScopedCache` — per-tenant key namespaces;
- `Psr6Bridge` / `Psr16Bridge` — PSR-6 and PSR-16 interoperability.

At runtime it depends only on interface packages — `kariricode/contract`
(exception families, DI provider) and `psr/clock` — as Spec V4 §31 requires
of KaririCode components. Metadata that XFetch needs travels with
each entry in the backend, so the behaviour is the same in PHP-FPM, CLI and
long-running workers.

**ARFA 1.3:** Level 1 (structural) — P1 immutable envelopes and value
objects, P4 adapters behind one port, P5 observation hooks. P2 and P3 are
not claimed.

## 2. Requirements

| Package | Required for |
|---|---|
| PHP >= 8.4 | everything |
| `kariricode/contract` ^2.11 | exception families, `CacheServiceProvider` |
| `psr/clock` ^1.0 | injectable clock (`SystemClock` by default) |
| ext-redis | `RedisAdapter` |
| ext-apcu (and `apc.enable_cli=1` on the CLI) | `ApcuAdapter` |
| ext-igbinary | `IgbinarySerializer` |
| ext-zlib | `ZlibCompressor` |
| `psr/simple-cache` ^3.0 | `Psr16Bridge` |
| `psr/cache` ^3.0 | `Psr6Bridge` |

## 3. Installation

```bash
composer require kariricode/cache
```

The runtime needs `kariricode/contract` ^2.11 and `psr/clock` ^1.0, both
interface packages; Composer installs them from Packagist.

## 4. Architecture

```
Cache ──────────── CacheAdapter ── ArrayAdapter | ApcuAdapter | RedisAdapter | FilesystemAdapter | NullAdapter
 │  EvictionPolicy     │ (Redis, Filesystem) PayloadCodec: Serializer → Compressor
 │  StampedePrevention (XFetch draws from Random\Randomizer)
 │  CacheEventListener
 │  ClockInterface (PSR-20; SystemClock by default)
TagAwareCache ──── CacheAdapter
LayeredCache ───── CacheAdapter × n (fastest first)
ScopedCache ────── any Cache
Psr16Bridge / Psr6Bridge ── any Cache
CacheServiceProvider ── CacheRegistry → default store as Cache, PSR-16, PSR-6
```

```
remember($key, $factory, $ttl)
  └─ read envelope (value, logical expiry, recompute cost δ)
       ├─ present and the strategy says "keep" → return value (hit)
       └─ missing, or selected to recompute
            └─ run $factory, measure δ (hrtime) → store new envelope → return value
```

## 5. Quick Start

```php
use KaririCode\Cache\Adapter\RedisAdapter;
use KaririCode\Cache\Cache;
use KaririCode\Cache\Configuration\RedisConnection;

$cache = new Cache(new RedisAdapter(new RedisConnection(host: '127.0.0.1'), prefix: 'app:'));

$cache->set('user:42', ['name' => 'Walmir'], ttl: 300);
$user = $cache->get('user:42');                       // ['name' => 'Walmir']

$report = $cache->remember('report:daily', fn (): array => buildReport(), ttl: 600);
```

## 6. Features

### 6.1 Adapters

| Adapter | Use | `flush()` removes |
|---|---|---|
| `ArrayAdapter(maxItems: 10_000, clock:)` | tests, CLI, fastest layer | its entries |
| `ApcuAdapter(prefix: 'app:')` | per-server shared memory | its prefix (whole APCu segment without prefix) |
| `RedisAdapter(connection: new RedisConnection(host:, port:, database:, password:, timeout:, readTimeout:), prefix:, codec:)` | shared across servers | its prefix via `SCAN` (`FLUSHDB` without prefix) |
| `FilesystemAdapter(directory: '/var/cache/app', options: new FilesystemOptions(...), codec:, clock:)` | persistent, single host | only files it created |
| `NullAdapter` | disable caching by configuration | — |

Backend failures raise `BackendException` (8600–8603); a backend that is
unreachable or refuses the password or database index raises
`ConnectionException` (8610); a missing extension raises
`ConfigurationException` (8641) when the adapter is built. An entry that
cannot be decoded is deleted and treated as a miss.

### 6.2 TTL and delete semantics

| TTL | Effect |
|---|---|
| `null` | no expiry |
| `> 0` | expires after that many seconds |
| `<= 0` | deletes the entry; the call returns `true` |

`delete()` is idempotent: it returns `true` for a missing key.

### 6.3 Fetch-or-compute with XFetch

`remember()` stores the value with its logical expiry and the measured
recompute cost δ. Before expiry, each caller recomputes with probability
exp(−gap/(δ·β)) (Vattani et al., VLDB 2015), so hot keys are usually
refreshed before they expire, without locks.

```php
use KaririCode\Cache\Stampede\XFetchPrevention;

$cache = new Cache($adapter, stampedePrevention: new XFetchPrevention(beta: 1.0));
```

XFetch is not single-flight: after expiry every caller recomputes until one
of them stores the new value.

### 6.4 Stale-while-revalidate

```php
$stats = $cache->rememberStale('dashboard', fn (): array => loadStats(), freshTtl: 60, staleTtl: 600);
```

Fresh for 60 s, kept for 600 s. The stampede strategy decides who
recomputes: XFetch (default) spreads early recomputations; with
`LockingPrevention` one caller per key recomputes after the fresh horizon
while the others get the stored value, inside one process (long-running
workers). Recomputation is synchronous; there is no refresh after the
response.

### 6.5 Tags

```php
use KaririCode\Cache\Tag\TagAwareCache;

$tagged = new TagAwareCache(new RedisAdapter(prefix: 'tags:'));

$tagged->setTagged('user:42:profile', $profile, ['user:42'], ttl: 3600);
$orders = $tagged->remember('user:42:orders', fn (): array => loadOrders(42), ttl: 600, tags: ['user:42', 'orders']);

$tagged->invalidateTags(['user:42']);                 // both entries become misses
```

Each read of an entry with *n* tags costs 1 + *n* backend reads. Keys must
not start with `__tag_version:`.

### 6.6 Multi-tier layering

```php
use KaririCode\Cache\Adapter\ApcuAdapter;
use KaririCode\Cache\Layered\LayeredCache;

$layered = (new LayeredCache())
    ->addLayer(new ApcuAdapter(prefix: 'l1:'), ttl: 60)
    ->addLayer(new RedisAdapter(prefix: 'l2:'), ttl: 3600);
```

Hits in a slower layer are copied to the faster ones for
min(layer TTL, time left on the entry); an explicit TTL is written unchanged
to every layer.

### 6.7 Scoping

```php
$tenant = $cache->withScope('tenant:42');
$tenant->set('settings', $settings);                  // stored as "tenant:42:settings"
```

`clear()` on a scope clears the whole underlying cache. To drop one tenant,
give it its own adapter prefix.

### 6.8 Eviction policies

```php
use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Enum\EvictionStrategy;
use KaririCode\Cache\Eviction\LruPolicy;

$cache = new Cache(new ArrayAdapter(maxItems: 0), new LruPolicy(capacity: 1_000));
$fromConfig = new Cache($adapter, EvictionStrategy::Lru->createPolicy(capacity: 1_000));
```

SIEVE (default, capacity 10 000), LRU, LFU, FIFO or none. `capacity: 0`
means unbounded. The
policy lives in process memory: it bounds `ArrayAdapter` and long-running
workers; with Redis or APCu under PHP-FPM, size the backend instead
(Redis `maxmemory-policy`, APCu `apc.shm_size`).

Measured with `benchmark/eviction_benchmark.php` (100 000 requests,
10 000 keys): SIEVE had a 12–19% lower miss ratio than LRU on stationary Zipf
workloads and 14–18% lower with bursts of one-time keys, but up to 14%
*higher* when popularity was reshuffled and the cache held 500 entries or
more. Results by workload are in SPEC-001 §10; run the script on your own
access pattern before switching policies.

### 6.9 Serialization and compression

```php
use KaririCode\Cache\Compression\ZlibCompressor;
use KaririCode\Cache\Serialization\PayloadCodec;
use KaririCode\Cache\Serialization\PhpSerializer;

$adapter = new RedisAdapter(
    prefix: 'app:',
    codec: new PayloadCodec(
        serializer: new PhpSerializer(allowedClasses: [Product::class]),
        compressor: new ZlibCompressor(threshold: 1024),
    ),
);
```

`PayloadCodec` serializes, then compresses; decoding runs the reverse. The
same codec serves `RedisAdapter` and `FilesystemAdapter`. With an
allowlist, objects of other classes come back as `__PHP_Incomplete_Class`;
the package's own envelopes are always allowed. Do not point the cache at a
backend that untrusted parties can write to.

### 6.10 Observation hooks

```php
use KaririCode\Cache\Contract\CacheEventListener;

final class MetricsListener implements CacheEventListener
{
    public function onHit(string $key): void { /* counter++ */ }
    public function onMiss(string $key): void { /* ... */ }
    public function onWrite(string $key, ?int $ttl): void { /* ... */ }
    public function onDelete(string $key): void { /* ... */ }
    public function onEviction(string $key, string $policyName): void { /* ... */ }
    public function onStampedeRecompute(string $key, float $gapSeconds): void { /* ... */ }
}

$cache = new Cache($adapter, eventListener: new MetricsListener());
```

Hooks run synchronously and are fired by `Cache` (not yet by
`TagAwareCache` or `LayeredCache`).

### 6.11 Cache warming

```php
use KaririCode\Cache\Warming\CacheWarmerRunner;

foreach ((new CacheWarmerRunner($cache, [new ProductCatalogWarmer()]))->warmAll() as $result) {
    printf("%s: %s (%.1f ms)\n", $result->name, $result->error?->getMessage() ?? 'ok', $result->elapsedMs);
}
```

### 6.12 Several stores

```php
use KaririCode\Cache\Registry\CacheRegistry;

$caches = (new CacheRegistry(defaultStore: 'default'))
    ->register('default', $cache)
    ->register('sessions', new Cache(new RedisAdapter(prefix: 'sess:')));

$caches->store('sessions')->set('abc', $session, 1800);
$caches->store()->get('user:42');                     // default store
```

An unknown store raises `StoreNotFoundException` 8620 (family `NotFound`);
call `has()` first when absence is expected.

### 6.13 Container integration

```php
use KaririCode\Cache\Provider\CacheServiceProvider;
use Psr\SimpleCache\CacheInterface;

$provider = new CacheServiceProvider(static fn (): CacheRegistry => (new CacheRegistry())
    ->register('default', new Cache(new RedisAdapter(prefix: 'app:'))));

$provider->register($container);   // any kariricode/contract BindableContainer
$provider->boot();

$cache = $container->get(CacheInterface::class);   // Psr16Bridge over the default store
```

`register()` binds four ids as singletons and builds nothing; the factory
runs on the first resolution.

| Id | Resolves to |
|---|---|
| `CacheRegistry::class` | the registry the factory returns |
| `KaririCode\Cache\Contract\Cache` | the default store |
| `Psr\SimpleCache\CacheInterface` | `Psr16Bridge` over the default store (when `psr/simple-cache` is installed) |
| `Psr\Cache\CacheItemPoolInterface` | `Psr6Bridge` over the default store (when `psr/cache` is installed) |

Other components should type against the PSR interfaces and receive the
cache this way, instead of duck-typing a cache object.

### 6.14 Time and randomness

Every "now" comes from a PSR-20 clock (`SystemClock` by default): memory and
file expiry, XFetch decisions, envelope timestamps and PSR TTL conversion.
XFetch draws, tag versions and layer TTL jitter come from
`Random\Randomizer` (secure engine by default). Injecting both makes tests
deterministic and benchmark runs reproducible:

```php
use Random\Engine\Mt19937;
use Random\Randomizer;

$cache = new Cache(
    new ArrayAdapter(clock: $clock),
    stampedePrevention: new XFetchPrevention(randomizer: new Randomizer(new Mt19937(42))),
    clock: $clock,
);
```

Two documented exceptions: the recompute cost δ is a duration and is
measured with `hrtime()` (monotonic; wall time can jump), and the
filesystem GC sampling and temporary file names use PHP's random functions,
which change when work happens, never a result.

## 7. PSR-6 / PSR-16 Conformance

```php
use KaririCode\Cache\Bridge\Psr16Bridge;
use KaririCode\Cache\Bridge\Psr6Bridge;

$simpleCache = new Psr16Bridge($cache);               // Psr\SimpleCache\CacheInterface
$pool = new Psr6Bridge($cache);                       // Psr\Cache\CacheItemPoolInterface
```

| Rule | Behaviour |
|---|---|
| Reserved key characters | `{}()/\@:` rejected with the PSR `InvalidArgumentException` |
| Other failures | PSR `CacheException` (original kept as previous) |
| TTL ≤ 0 (int or `DateInterval`) | deletes |
| Cached `null` | a hit |
| PSR-6 deferred items | visible before `commit()`, committed on destruction |

The native API also accepts `:` in keys (used by scoping). Covered by
`tests/Conformance/Psr6ConformanceTest.php` and `Psr16ConformanceTest.php`.

## 8. Error Codes

| Code | Exception | Family | Meaning |
|---|---|---|---|
| 8600–8603 | `BackendException` | Runtime | backend read, write, delete, flush failed |
| 8604 | `BackendException` | Runtime | payload cannot be (un)serialized |
| 8605 | `BackendException` | Runtime | compressed payload is corrupt |
| 8610 | `ConnectionException` | Runtime | cannot connect to the backend |
| 8620 | `StoreNotFoundException` | NotFound | `CacheRegistry` store not registered |
| 8630–8632 | `InvalidKeyException` | Validation | empty key, reserved character, reserved prefix |
| 8640 | `ConfigurationException` | Configuration | invalid option or argument |
| 8641 | `ConfigurationException` | Configuration | required extension missing or disabled |
| 8650, 8651 | PSR-16 bridge exceptions | Validation, Runtime | invalid key, backend failure |
| 8652, 8653 | PSR-6 bridge exceptions | Validation, Runtime | invalid key, backend failure |

Every exception implements the marker interface `CacheException` (catch it
for any cache failure) and exactly one `kariricode/contract` 2.11 family,
which the edges map to responses. Messages never echo keys, rejected values
or store names (Spec E-047). Package range 8600–8699; 8660–8699 reserved.

The 4.0 codes (1001–1005, 2001, 3001–3002) collided with other KaririCode
packages and are retired. The 8400–8499 block used by 4.1.0 drafts moved to
8600–8699 because `kariricode/authenticator` registered 8400–8599 (ADR-013).

## 9. Testing and Quality Gates

The toolchain is installed by [KaririCode Devkit](https://github.com/KaririCode-PHP/devkit)
(`kcode`) at the versions `devkit.php` pins, and runs with the configs at the
root of this repository (ADR-015):

```bash
# kcode 1.2.1, checked against its published SHA-256
curl -fsSLo kcode.phar https://github.com/KaririCode-PHP/devkit/releases/download/v1.2.1/kcode.phar
echo "1bc43b06798b4e7fc1df341cc505f316549f0dd5efb9c8d80031415b0cdf3e3b  kcode.phar" | sha256sum -c -
sudo install -m 0755 kcode.phar /usr/local/bin/kcode

composer install   # runtime from composer.lock: kariricode/contract, psr/clock
kcode init         # toolchain into .kcode/
```

APCu runs in the CLI only with `apc.enable_cli=1` in `php.ini` (CI sets it
through setup-php). The gate is the command set of Spec V4.3.3 §29.1:

```bash
composer check             # cs-check, phpstan, psalm, complexity, test, test-conformance, security
composer test              # Unit suite
composer test-integration  # Redis, APCu and filesystem
composer test-conformance  # PSR-6/PSR-16 and ARFA/Spec conformance
composer test-coverage     # line coverage (pcov or Xdebug)
composer infection         # mutation testing; fails below 80 % MSI or covered MSI
composer sbom              # CycloneDX 1.5 SBOM in sbom.cdx.json
```

`security` runs `composer validate --strict`, `composer audit` of the
runtime and of the toolchain, the secret
scan (with its self-test) and the `security` test group (fail-closed
behaviour: payload allowlist, undecodable entries, key rules, messages that
never echo input). Integration tests use Redis at
`KARIRICODE_CACHE_REDIS_HOST`/`_PORT` (default `127.0.0.1:6379`, database 15)
and are skipped when it is not reachable.

Measured for 4.1.0 (PHP 8.4.26, Redis 7.0.15, 1 vCPU): 503 tests and 29 689 assertions
passing (Unit 393, Integration 66, Conformance 44), nothing skipped; line
coverage 95.5 % (1142 of 1196), methods 87.4 %; PHPStan level max + strict
+ cognitive ≤ 15 and Psalm level 1: 0 errors; complexity budgets: 376
methods within; mutation score 88.4 % MSI and covered MSI (1 444 mutants,
minimum 80 %).

## 10. Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Pull requests must pass
`composer check`, `composer test-integration` and `composer infection`.
Security policy and threat model: [SECURITY.md](SECURITY.md).

## 11. Changelog

See [CHANGELOG.md](CHANGELOG.md). 4.1.0 contains breaking changes and an
upgrade table.

## 12. License

MIT — see [LICENSE](LICENSE).

## 13. About KaririCode

Part of the [KaririCode Framework](https://kariricode.org) ecosystem, built on
the ARFA 1.3 specification and the KaririCode Code Quality Specification V4.
