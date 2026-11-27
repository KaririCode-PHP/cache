# ADR-003: Zero Third-Party Runtime Dependencies

## Status

Accepted — V4.0.0 · Revised V4.1.0 · Amended by ADR-013

## Date

2026-03-01 · revised 2026-09-30 · amended 2026-10-02

## Context

A cache sits under every other component. Each runtime dependency it pulls
in becomes a dependency of the whole application and a version constraint
to reconcile.

### Prior Art

- `symfony/cache` requires several packages (PSR interfaces, Symfony
  contracts, var-exporter).
- PSR-6 and PSR-16 are interfaces only; they are needed only by code that
  consumes the PSR interfaces.

## Decision

`require` contains only `php: ^8.4`. Everything else is optional:

| Need | How |
|---|---|
| PSR-6 / PSR-16 interoperability | `Bridge/`; `psr/cache`, `psr/simple-cache` in `suggest` |
| Redis, APCu, igbinary, zlib | PHP extensions in `suggest`; adapters fail fast (8641) when missing |
| Metrics, traces, logs | implement `CacheEventListener` |

### Revision 4.1.0

- **The package was not installable.** 4.0.x required
  `kariricode/contract: ^3.0`. Packagist has no 3.x (the latest is 2.8.6) and
  no class from it was imported, so `composer require kariricode/cache`
  failed. The requirement was removed.
- **Development tools are explicit.** `require-dev` lists PHPUnit 12,
  PHPStan 2 with strict rules, PHP-CS-Fixer and the two PSR packages,
  replacing `kariricode/devkit`, as in EventDispatcher 4.1.0.

### Amendment (ADR-013, 2026-10-02)

`require` now holds `php`, `kariricode/contract ^2.11` (exception families,
DI provider) and `psr/clock ^1.0` (injectable clock, ADR-014). Both are
interface packages, which is what Spec V4 §31 allows components to couple
through. What this ADR protects still holds: no third-party
implementation is pulled in at runtime. The negative consequence below —
shared contracts unusable until a Contract release has them — is what
ADR-013 resolves; until 2.11.0 is on Packagist the package installs the
contract from a path fixture.

## Consequences

### Positive

- `composer require kariricode/cache` adds two interface packages and no
  implementation.
- Bridges load PSR interfaces only when used.

### Negative

- Shared contracts from `kariricode/contract` cannot be reused until a
  Contract release that contains them is published.
- Bridge classes fail at autoload time if the PSR package is missing.

## Alternatives Considered

1. **Require psr/cache and psr/simple-cache** — rejected: most consumers use
   the native API.
2. **Keep kariricode/contract** — rejected: the required version does not
   exist and nothing from it is used.

## References

- Packagist, kariricode/contract — https://packagist.org/packages/kariricode/contract
- Composer schema, `suggest` — https://getcomposer.org/doc/04-schema.md#suggest
- ARFA 1.3 — P4 (Protocol Agnosticism)
