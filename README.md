# kariri-cache
Camada de cache que proporciona mecanismos de armazenamento temporário de dados, melhorando o desempenho por evitar a repetição de operações custosas.

A flexible and efficient caching library for PHP with support for multiple drivers and plugin system

## Development

This package keeps no QA tools in `require-dev`. [KaririCode Devkit](https://github.com/KaririCode-PHP/devkit) (`kcode`) installs PHPUnit, PHPStan, Psalm, PHP-CS-Fixer and Rector into `.kcode/` (git-ignored) the first time a command needs them.

Install `kcode` once (PHP 8.4+):

```bash
wget https://github.com/KaririCode-PHP/devkit/releases/latest/download/kcode.phar
chmod +x kcode.phar
sudo mv kcode.phar /usr/local/bin/kcode
```

From the repository root:

```bash
composer install          # runtime dependencies only
kcode init                # generate .kcode/ and install the toolchain (first run)
kcode test                # PHPUnit (--coverage, --suite=Unit, any PHPUnit option)
kcode analyse             # PHPStan, then Psalm
kcode cs:fix              # apply the code style (--check reports only)
kcode quality             # the CI gate: cs-fixer, PHPStan, Psalm, PHPUnit
kcode quality --docker    # the same gate inside the kcode PHP container
```

Project overrides go in `devkit.php` (`kcode init --config` writes a template); run `kcode init` after editing it. `kcode clean --all` removes `.kcode/`.
