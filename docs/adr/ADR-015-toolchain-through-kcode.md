# ADR-015: Quality Toolchain through KaririCode Devkit, with the Root Configs

## Status

Accepted — 2026-11-27

## Date

2026-11-27

## Context

4.1.0 ran its gates from `require-dev` and took kariricode/contract 2.11 from a path fixture, so `composer.lock` could not be committed (ADR-013, decision 5). Meanwhile the ecosystem settled on KaririCode Devkit (`kcode`) for the toolchain, and Devkit 1.2.1 runs a component's committed configs (`'root_configs' => true`), so the Specification V4.3.3 gates keep their strength. kariricode/contract, exception, property-inspector, class-discovery, processor-pipeline, console, database, orm and router made the same move, each in an ADR of its own. kariricode/contract 2.12.0 is on Packagist.

## Decision

- `require-dev` is removed. `devkit.php` pins the tools exactly: PHPUnit 12.5.37, PHPStan 2.3.7 with phpstan-strict-rules 2.1.2 and tomasvotruba/cognitive-complexity 1.2.0, Psalm 6.19.1, PHP-CS-Fixer 3.95.27 and Infection 0.35.5. kcode installs them into `.kcode/vendor/`.
- psr/cache 3.0.0 and psr/simple-cache 3.0.0 are listed there too. They are not tools: the tests and the analysers need them for `Psr6Bridge` and `Psr16Bridge`, which stay `suggest` dependencies. `tests/bootstrap.php` loads the project's autoloader first and the toolchain's second; PHPStan scans both packages and Psalm reads them as extra files.
- The root configs stay and are what kcode runs. Paths that pointed into `vendor/` point into `.kcode/vendor/`; Infection gets the toolchain's PHPUnit as its custom path and writes its logs under `build/`.
- The scripts and gates are unchanged: `composer check`, `test-integration`, `infection`, `sbom`. They call kcode; `composer security` also audits the toolchain. APCu needs `apc.enable_cli=1` in `php.ini`, because kcode runs PHPUnit without `-d` flags; CI sets it through setup-php.
- The path fixture is deleted and `composer.lock` is committed with the runtime only: kariricode/contract 2.12.0 and psr/clock 1.0.0 (ADR-013, Amendment). `tools/sbom.php` reads the development components from `.kcode/composer.lock`.
- CI downloads kcode 1.2.1 by version and checks its SHA-256 before `kcode init`; the Redis service stays pinned by digest and the actions by SHA.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Keep the tools in `require-dev` | the rest of the ecosystem installs them through kcode |
| kcode with its generated configs | PHPStan level 9 without strict rules, Psalm level 3, no mutation or conformance gate: weaker than §29.1 |
| Unpinned tools | a gate result could not be reproduced from the commit that produced it (§32) |
| Run PHPUnit with `php -d apc.enable_cli=1` in the scripts | bypasses kcode for one setting the runtime configuration already owns |

## Consequences

- One toolchain model across the ecosystem; this package's gates are unchanged.
- Contributors install kcode once and enable APCu in the CLI.
- Transitive versions of the toolchain resolve at install time until Devkit commits a `devkit.lock`.

## References

- KaririCode Specification V4.3.3 §29.1, §31, §32.
- KaririCode Devkit 1.2.1 (`root_configs`); ADR-013 (contract 2.11 alignment).
