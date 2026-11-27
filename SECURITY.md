# Security Policy — KaririCode Cache

## Supported Versions

| Version | Supported |
|---|---|
| 4.1.x | yes |
| 4.0.x | no — it could not be installed (it required a missing `kariricode/contract` version) |

## Reporting a Vulnerability

Report privately to the maintainers — through GitHub private vulnerability
reporting once it is enabled for the repository — and do not open a public
issue with details. Include the version, the backend involved and a minimal
reproduction.

## Scope

The library stores PHP values in memory, APCu, Redis or files and reads
them back. It has no network listener of its own; its attack surface is
what it reads from backends and the keys and values callers pass in.
Declared assurance level: OWASP ASVS 5.0 level 1 (`composer.json`,
`extra.kariricode.asvs`).

ASVS 5.0 areas that apply to a cache library:

| ASVS 5.0 | How it applies here |
|---|---|
| V1.5 Safe Deserialization | backend payloads are unserialized: class allowlist, undecodable entries become misses |
| V2 Validation and Business Logic | key rules (8630–8632), fail-fast options (8640) |
| V14 Data Protection | cached values may hold personal data: per-tenant prefixes, no keys or values in exception messages |
| V15.2 Security Architecture and Dependencies | runtime coupling to interface packages only; `composer audit`; SBOM |
| V15.3 Defensive Coding | strict types, PHPStan level max and Psalm level 1, no extension exception leaks |

Transport security (ASVS V12) is a deployment decision: the package does not
configure TLS for Redis. A mapping to individual ASVS requirement IDs is not
produced yet.

## Assets and Trust Boundaries

| Asset | Where | Trusted? |
|---|---|---|
| Cached values (may contain personal data) | backend | only if the backend is private to the application |
| Serialized payloads | Redis, files, APCu | as trusted as whoever can write to that backend |
| Redis credentials | `RedisConnection` | secret |
| Cache keys | callers | untrusted when derived from user input |

## Threats and Mitigations

| Threat | Mitigation | Residual risk |
|---|---|---|
| Object injection through `unserialize()` of a tampered backend entry (A08) | `PhpSerializer(allowedClasses: [...])` allowlist; package envelopes always admitted; corrupt payloads raise 8604 and become misses | with the default `allowedClasses: true`, anyone who can write to the backend can instantiate arbitrary classes on read; igbinary has no allowlist |
| Cache poisoning by another tenant or application sharing the backend | per-tenant prefixes (`withScope()`, adapter prefixes); `flush()` limited to the adapter's own prefix or files | a shared backend without authentication cannot be defended by the library |
| Key injection into backend commands or paths | keys validated (8630–8632); filesystem paths use a hash of the key; Redis keys are sent as arguments, never interpolated | — |
| Destructive clear of foreign data | `FilesystemAdapter::flush()` deletes only files it named; Redis `flush()` scans its prefix | Redis `flush()` without a prefix is `FLUSHDB` (documented) |
| Credential exposure | password private in `RedisConnection`, `#[\SensitiveParameter]` | `var_dump()` of the object still shows private properties |
| Denial of service by unbounded growth | policy capacity, `ArrayAdapter(maxItems)`, backend limits (ADR-010) | under PHP-FPM with a shared backend only backend limits apply |
| Personal data in logs through exception messages | messages never echo keys, rejected values or store names (E-047); `ExceptionTest::testMessagesNeverEchoARejectedValue` | values a listener logs are the listener's responsibility |
| Failure hiding (A10) | backend errors raise `BackendException` (8600–8603, 8610); unreadable entries are misses, never partial values | a listener that throws aborts the cache call (ADR-007) |

## Fail-Closed Behaviour

The `security` test group (`composer security` runs it; 109 tests) pins the
properties above:

- payloads outside the allowlist come back as `__PHP_Incomplete_Class`; the
  package's own envelopes are always admitted;
- an entry that cannot be decoded is deleted and read as a miss — never a
  partial value;
- keys break no backend command or path; the PSR bridges apply the full PSR
  reserved set;
- backend failures surface as `BackendException`/`ConnectionException` with
  the cause kept as the previous exception; `\RedisException` never leaks;
- a Redis database index or password the server refuses fails the
  connection; it never falls back to database 0;
- `FilesystemAdapter::flush()` deletes only files it named;
- exception messages never echo a key, a rejected value or a store name.

## Supply Chain (Specification V4.3.3 §32)

| Control | State |
|---|---|
| CI actions pinned by commit SHA, Redis image by digest | done (`.github/workflows/ci.yml`) |
| `persist-credentials: false` on checkout | done |
| Dependency advisories | `composer audit` of the runtime and of the toolchain, in `composer security` |
| Secret scanning | `tools/secret-scan.php`, with a self-test of every pattern |
| SBOM | `composer sbom` → CycloneDX 1.5 `sbom.cdx.json` (CI artifact) |
| Lock file | committed with the runtime only (kariricode/contract 2.12.0, psr/clock 1.0.0); the toolchain is pinned in `devkit.php` and audited from `.kcode/composer.lock` (ADR-015) |
| Signed releases and tags | pending — release time |

## References

- OWASP ASVS — https://owasp.org/www-project-application-security-verification-standard/
- OWASP Cheat Sheet Series, ASVS 5.0 index — https://cheatsheetseries.owasp.org/IndexASVS.html
- OWASP Top 10:2025 A03 Software Supply Chain Failures — https://owasp.org/Top10/2025/
