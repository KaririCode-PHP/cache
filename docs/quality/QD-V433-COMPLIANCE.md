# QD-V433 Compliance Report — KaririCode Cache 4.1.0

**Status: PENDING** — the local gate passes; the items in "Open" close
outside this repository or at release time. No score is assigned.

Measured on 2026-10-02 with PHP 8.4.26, Redis 7.0.15 and APCu, 1 vCPU.

## Evidence

| Rule | Requirement | Evidence | Status |
|---|---|---|---|
| §13 | cyclomatic ≤ 10, cognitive ≤ 15, nesting ≤ 3, parameters ≤ 4 | `composer complexity`: 376 methods within budgets; PHPStan cognitive rule: 0 errors | met — the soft 10-public-method budget is exceeded by the 11 classes that implement the 11-method contracts (reported, not failing) |
| §17 | docblocks C1–C5, no redundant tags | `ArfaConformanceTest` over 60 source files | met |
| §18 | banned names | `CodeBudgetConformanceTest` | met |
| §19.1 | test layers | backend tests in `tests/Integration` | met |
| §19.2 | injectable time and randomness | ADR-014; `InjectedTimeAndRandomnessTest` (frozen clock, seeded engines) | met, with the two exceptions documented in ADR-014 |
| §19.4 | MSI and covered MSI ≥ 80 % | `composer infection` | met — MSI and covered MSI 88.4 % (1 276 of 1 444 mutants detected); 66 of the 168 survivors are equivalent by construction (see below) |
| §19.5 | coverage floors | `composer test-coverage`: lines 95.5 % (1142 of 1196), methods 87.4 % overall | overall floor met; the domain floor is not broken out per directory in this run |
| §24 | security gate | `composer security`: manifest valid, no advisories, secret scan clean (self-tested), `security` group 109 tests | met; signed releases open |
| §29.1 | gate commands | `composer check` exit 0; `test-integration`, `infection`, `sbom` present and run in CI | met |
| §29.2 | magic methods, static state | `CodeBudgetConformanceTest` | met |
| §30 | named arguments at three or more arguments | `tools/complexity.php`: 0 positional calls | met |
| §31 | coupling through contract and PSR only | runtime `require`: `php`, `kariricode/contract`, `psr/clock` (`ArfaConformanceTest`) | met |
| §32 | supply chain | actions pinned by SHA, image by digest, `persist-credentials: false`, CycloneDX SBOM | partial — lock not committed while the contract comes from the path fixture; signing open |
| E-040 | value objects expose derived values as query methods | `CacheEntry`, `AdapterStats` | met |
| E-044 | `extra.kariricode.spec` | `"spec": "4.3.3"` | met |
| E-045/E-046 | PER Coding Style 3.1 | `@PER-CS3x0` (php-cs-fixer has no 3.1 set): 0 of 124 files to fix | met with the documented deviation |
| E-047 | no echoed values in messages | `ExceptionTest::testMessagesNeverEchoARejectedValue` | met |
| Contract 2.11 | exception families, DI provider | ADR-013; `ExceptionTest`, `CacheServiceProviderTest` | met against the path fixture |

## Mutation Testing

Infection 0.35.5 over `src/` with the Unit and Integration suites, run with no
concurrent load: 1 444 mutants, 1 274 killed, 2 time-outs, 168 survivors —
MSI and covered MSI 88.4 %. No mutator and no file is excluded. Survivors
classified as equivalent by construction, counted by source line:

| Survivors | Why no test can tell them apart |
|---|---|
| 20 | fallbacks for non-numeric replies from the APCu and Redis extensions |
| 9 | TTL ≤ 0 re-checked by a composed cache; the adapter below applies the same rule |
| 8 | temporary file name; every variant is renamed to the same target |
| 8 | file header parsing; `is_numeric()` accepts the trailing newline |
| 5 | PSR-6 key type guard; PHP array keys are always `int` or `string` |
| 4 | PING replies that phpredis 6 never returns |
| 4 | marker values stored but never read |
| 3 | GC sampling probability, observable only at 0 or 100 % |
| 3 | LFU minimum, which `evict()` recomputes when its bucket is gone |
| 2 | allowlist de-duplication |

The other 102 are not classified one by one; `infection.log` (a CI artifact)
lists them. They include failure paths a root test run cannot provoke
(refused `unlink()`, unwritable directories) and defensive housekeeping
(lock pruning, the SIEVE scan bound, error-handler restoration).

Two production defects were found while writing the tests for the first
run: `ApcuAdapter::stats()` reported `memoryBytes: 0` (APCu returns
`mem_size` as a float), and `RedisConnection` ignored a refused `SELECT` or
`AUTH`, staying on database 0.



1. `kariricode/contract` 2.11.0 on Packagist; then remove the path fixture
   and commit `composer.lock`.
2. Signed tag and release artifacts.
3. Mapping of ASVS 5.0 requirement IDs (the applicable areas are listed in
   `SECURITY.md`).
4. Error-code registry owned by the contract or the Specification; the
   collisions between other packages remain open.
