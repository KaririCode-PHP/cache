# ADR-013: Alignment with kariricode/contract 2.11

## Status

Accepted — V4.1.0

## Date

2026-10-02

## Context

`kariricode/contract` 2.11.0, written together with Specification V4.3.3,
adds what components need to plug into the framework without knowing each
other:

- exception families in `KaririCode\Contract\Exception`: a root
  `ExceptionInterface` and `Validation`, `NotFound`, `Configuration`,
  `Protocol`, `ContainerFailure`, `Runtime`. Each component exception
  implements exactly one, and the edges (HTTP, CLI) map families to
  responses without knowing component classes;
- `DI\ServiceProvider` (`register(BindableContainer)`, `boot()`) and
  `DI\BindableContainer` (`get`, `has`, `bind`, `instance`).

The package did not fit:

- `CacheException` was a concrete, non-final base class carrying the backend
  factories; a key error and a backend error shared it, so no single family
  could describe it.
- `ConnectionException` mixed an unreachable server with a missing PHP
  extension, which is a configuration problem of the environment.
- Messages echoed keys, store names and rejected values. Keys routinely
  carry personal data (`user:alice@example.com`) and messages reach logs;
  Spec erratum E-047 forbids echoing rejected values.
- The 4.1.0 drafts used 8400–8499, and `kariricode/authenticator` ADR-010
  (2026-10-01) registered 8400–8599.

Versions 2.9 to 2.11 of the contract are not on Packagist (2.8.6 is the
latest). RateLimiter, Observability and Authenticator install it from a
local fixture through a `path` repository and do not commit `composer.lock`.

## Decision

1. **Marker plus families.** `CacheException` becomes a marker interface
   extending the contract's `ExceptionInterface`. Every concrete exception is
   final, extends the fitting SPL exception and implements exactly one family:

   | Exception | SPL parent | Family | Codes |
   |---|---|---|---|
   | `BackendException` | `RuntimeException` | Runtime | 8600–8605 |
   | `ConnectionException` | `RuntimeException` | Runtime | 8610 |
   | `StoreNotFoundException` | `OutOfBoundsException` | NotFound | 8620 |
   | `InvalidKeyException` | `InvalidArgumentException` | Validation | 8630–8632 |
   | `ConfigurationException` | `LogicException` | Configuration | 8640–8641 |
   | `Psr16InvalidArgumentException`, `Psr6InvalidArgumentException` | `InvalidArgumentException` | Validation | 8650, 8652 |
   | `Psr16CacheException`, `Psr6CacheException` | `RuntimeException` | Runtime | 8651, 8653 |

   `ConfigurationException` replaces `InvalidConfigurationException` and
   takes over the missing-extension case.
2. **Range 8600–8699**, in sub-ranges of ten per exception kind; 8660–8699
   reserved.
3. **No echoed input (E-047).** Messages state the rule or the option name,
   never the key, the rejected value or the store name. Diagnostics stay in
   the previous exception. `ExceptionTest` proves it with a sensitive key.
4. **`CacheServiceProvider`.** Takes a closure that builds the
   `CacheRegistry`; `register()` binds `CacheRegistry`, the native `Cache`
   contract, `Psr\SimpleCache\CacheInterface` and
   `Psr\Cache\CacheItemPoolInterface` as lazy singletons over the default
   store (the PSR ones only when the PSR package is installed); `boot()` does
   nothing.
5. **Path fixture until publication.** `tools/contract-2.11-fixture/` holds
   the 2.11 interfaces this package uses, with the same names and
   signatures; `composer.json` declares it as a `path` repository and
   `composer.lock` is not committed. Both go away once 2.11.0 is published.
6. **No cache interface in the contract.** The contract keeps PSR-6 and
   PSR-16 as the ecosystem's cache ports (ADR-011). Consumers such as Router
   and Authorization type against PSR-16 and receive `Psr16Bridge` from the
   provider.

## Consequences

### Positive

- Edges handle cache failures by family; `catch (CacheException)` still
  catches everything the package throws.
- Containers wire the package the same way as the sibling components.
- No code collides with another KaririCode package.

### Negative

- Breaking for code that extended or instantiated `CacheException`, caught
  `InvalidConfigurationException`, or compared codes in 8400–8499; the
  CHANGELOG lists each change.
- Messages carry less detail; the key is no longer in the text.
- Until 2.11.0 is published, an application that requires this package must
  declare a repository for the contract itself, because Composer resolves
  repositories from the root package only.

## Alternatives Considered

1. **Keep the base class and add families to it** — rejected: one class
   cannot be both Validation and Runtime.
2. **Keep 8400–8499 and ask Authenticator to move** — rejected:
   Authenticator registered its range first, in a published ADR.
3. **Wait for 2.11.0 on Packagist** — rejected: the siblings are already
   aligned, and the fixture keeps the gate runnable meanwhile.

## References

- kariricode/contract 2.11.0 — exception families and DI provider
- KaririCode Specification V4.3.3, erratum E-047 (no echoed values)
- kariricode/authenticator ADR-010 — error range 8400–8599
- PHP SPL exceptions — https://www.php.net/manual/en/spl.exceptions.php
- Composer, path repositories — https://getcomposer.org/doc/05-repositories.md#path
- Composer, repositories are root-only — https://getcomposer.org/doc/04-schema.md#repositories

## Amendment (2026-11-27)

kariricode/contract 2.12.0 is on Packagist, so decision 5 is closed: the
path fixture is gone, `composer.lock` is committed with contract 2.12.0 and
psr/clock 1.0.0, and the constraint stays `^2.11`. The toolchain moved to
KaririCode Devkit in the same import (ADR-015).

