# Contributing to KaririCode Cache

## Requirements

- PHP 8.4+ with ext-redis, ext-apcu, ext-igbinary and ext-zlib for the full
  suite (pcov for coverage and mutation testing)
- Composer 2.x
- Redis for the integration tests (`KARIRICODE_CACHE_REDIS_HOST`,
  `KARIRICODE_CACHE_REDIS_PORT`; default `127.0.0.1:6379`, database 15)

## Setup

```bash
git clone git@github.com:KaririCode-PHP/cache.git
cd cache
composer install   # runtime from composer.lock: kariricode/contract, psr/clock
kcode init         # the toolchain devkit.php pins, into .kcode/
```

[KaririCode Devkit](https://github.com/KaririCode-PHP/devkit) (`kcode`)
installs the tools and runs this repository's configs (ADR-015). Install
1.2.1 and check it against its published SHA-256, as the README shows. APCu
needs `apc.enable_cli=1` in `php.ini` for the CLI.

## Quality Gates

Every pull request must pass the gate of Specification V4.3.3 §29.1:

```bash
composer check             # cs-check, phpstan, psalm, complexity, test, test-conformance, security
composer test-integration  # Redis, APCu, filesystem
composer infection         # MSI and covered MSI >= 80 %
```

| Gate | Command | Configuration |
|---|---|---|
| Code style | `composer cs-check` (`composer cs-fix` applies) | `.php-cs-fixer.dist.php` — `@PER-CS3x0`, strict types, alphabetical imports, no unused imports |
| Static analysis | `composer phpstan` | `phpstan.neon` — level max, phpstan-strict-rules, cognitive complexity ≤ 15; no baseline, no inline ignores |
| Static analysis | `composer psalm` | `psalm.xml` — error level 1; every exemption is justified in the file |
| Budgets | `composer complexity` | `tools/complexity.php` — §13 budgets and §30 named arguments |
| Tests | `composer test`, `test-integration`, `test-conformance` | `phpunit.xml.dist`; APCu needs `apc.enable_cli=1` in `php.ini` |
| Security | `composer security` | `validate --strict`, `audit` of the runtime and the toolchain, secret scan with self-test, `security` test group |
| Mutation | `composer infection` | `infection.json5` — `src/`, Unit and Integration suites |
| SBOM | `composer sbom` | CycloneDX 1.5 |

## Code Standards

The package follows ARFA 1.3 and the KaririCode Specification V4:

1. `declare(strict_types=1)` in every file; one class, interface or enum per
   file, tests and fixtures included.
2. Concrete classes are `final`. Value objects and DPOs are `final readonly`
   and expose derived values as query methods (E-040); property hooks only
   on non-readonly classes. Enums are pure unless a backing value is
   justified (E-003).
3. No `toArray()`/`fromArray()`/`jsonSerialize()`, no `get*()` getters
   outside externally mandated interfaces (PSR-6). Exceptions: implement the
   marker `CacheException` and exactly one `kariricode/contract` family,
   extend the fitting SPL exception, are created by semantic factories with a
   numeric code, and never echo keys, rejected values or store names (E-047).
4. Fail fast: invalid options are rejected at construction or call time.
5. Backend and PSR types stay at the edges (`Adapter/`, `Bridge/`); PHP
   extension exceptions never leak.
6. Keep the documented semantics (ADR-010): `null` TTL = no expiry,
   TTL ≤ 0 deletes, deletes are idempotent.
7. Budgets (Specification §13): cyclomatic complexity ≤ 10 (a `switch` or
   `match` counts once; closures count toward their method), cognitive
   complexity ≤ 15, nesting ≤ 3, at most 4 parameters — beyond that,
   introduce a parameter object. Constructors of readonly classes whose
   parameters are all promoted are exempt: they are the parameter object
   (the rule most sibling packages use).
8. Banned names (§18): no `Manager`, `Helper`, `Util` or `Factory` classes and
   no `Interface` suffix; a class that only wraps `new` is replaced by a
   constructor or a named constructor.
9. Magic methods (§29.2): only `__construct`, plus `Psr6Bridge::__destruct`
   (PSR-6 requires deferred items to be persisted); no static properties.
10. Named arguments (§30): method, static and constructor calls with three or
    more arguments name them.
11. Time and randomness (§19.2) are injected: a PSR-20 clock and a
    `Random\Randomizer`; never call `microtime()`, `time()` or `random_int()`
    in `src/` outside the exceptions listed in ADR-014.
12. `#[\Override]` on every method that implements or overrides another.

Rules 7 and 10 are enforced by `tools/complexity.php` (also run by
`CodeBudgetConformanceTest`) and PHPStan; rules 8 and 9 by
`CodeBudgetConformanceTest`.

### Documentation

- Each source type's docblock follows Specification V4.3.3 §17: C1 the
  role, then `ARFA 1.3` and `Spec V4` lines; C2 `Preconditions:` and
  `Thread-safety:`; C3 `Side effects:`; C4 `Example:` with indented code; C5
  `References:` (ADRs, SPEC sections) and an `@see` URL. No `@package`,
  `@author`, `@license` or `@since`. `ArfaConformanceTest` enforces it.
- No tautological docs ("Gets the…", "Executes the…", "Represents a…") and
  no `{@inheritDoc}`.
- Section markers are exactly 72 characters (`// ── Name ───…`).
- Claims in docs need a source or a measurement (benchmark script, test).

### Testing

- Every behaviour change comes with a test; every bug fix with a regression
  test that fails on the previous code.
- Unit tests isolate one class; anything that touches Redis, APCu or the
  filesystem is an integration test (Specification §19.1) and skips when the
  backend is unavailable; the conformance suite covers PSR-6, PSR-16, the
  ARFA structure and the complexity budgets.
- Inject `tests/Fixtures/FrozenClock` and a seeded `Randomizer`
  (`new Randomizer(new Mt19937(42))`) instead of sleeping or retrying.
- Tests that pin a safety property carry `#[Group('security')]`.
- Mutation testing (§19.4): MSI and covered MSI ≥ 80 % (`composer infection`).
- Coverage floors (§19.5): ≥ 90% on the cache core, ≥ 80% overall
  (`composer test-coverage`, with pcov or Xdebug).

## Architecture Decisions

Read `docs/adr/` before structural changes. New decisions get an ADR:

```markdown
# ADR-NNN: Title

## Status
Proposed | Accepted — V<version> | Superseded by ADR-NNN

## Date
YYYY-MM-DD

## Context
### Prior Art

## Decision

## Consequences
### Positive
### Negative

## Alternatives Considered

## References
```

## Pull Request Process

1. Branch from `main`.
2. Write the failing test first.
3. Implement, then run `composer check` and `composer test-integration`.
4. Add an entry under `[Unreleased]` in `CHANGELOG.md`.
5. Reference the ADRs and SPEC sections your change touches.

## Error Codes

This package owns 8600–8699:

| Range | Exception | Family |
|---|---|---|
| 8600–8609 | `BackendException` — backend I/O and payload encoding (8600–8605 used) | Runtime |
| 8610–8619 | `ConnectionException` (8610 used) | Runtime |
| 8620–8629 | `StoreNotFoundException` (8620 used) | NotFound |
| 8630–8639 | `InvalidKeyException` (8630–8632 used) | Validation |
| 8640–8649 | `ConfigurationException` (8640–8641 used) | Configuration |
| 8650–8659 | PSR bridge exceptions (8650–8653 used) | Validation, Runtime |
| 8660–8699 | reserved | — |

Retired: the 4.0 codes 1001–1005, 2001 and 3001–3002, and the 8400–8499
block of the 4.1.0 drafts (ADR-013). `tests/Unit/Exception/ExceptionTest.php`
enforces unique codes inside the range, one family per exception and
messages without echoed input.

Ecosystem allocation as known on 2026-10-02 (open collisions between other
packages: Runtime × I18n, CLI × Tenancy, DataFlow's two maps):

| Range | Package |
|---|---|
| 1000–1499 | AsyncPrimitives (formerly AsyncHelper) |
| 2000–2499 | EventLoop |
| 3000–3049 | Async |
| 3100–3169 | DependencyInjection |
| 4000–4099 | Queue |
| 5000–5199 | DataFlow |
| 6000–6199 | Database |
| 7000–7099 | Template |
| 7100–7199 | CircuitBreaker |
| 7300–7499 | Authorization |
| 8100–8199 | EventDispatcher |
| 8200–8249 | Observability |
| 8300–8399 | RateLimiter |
| 8400–8599 | Authenticator |
| 8600–8699 | Cache |
| 9000–9099 | Console |
| 9100–9199 | I18n |
| 9200–9299 | Tenancy |
