# KaririCode Cache - Um Componente do KaririCode Framework

[![en](https://img.shields.io/badge/lang-en-red.svg)](README.md)
[![pt-br](https://img.shields.io/badge/lang-pt--br-green.svg)](README.pt-br.md)

![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![PHPUnit](https://img.shields.io/badge/PHPUnit-78E130?style=for-the-badge&logo=phpunit&logoColor=white)

## Desenvolvimento

Este pacote não mantém ferramentas de QA no `require-dev`. O [KaririCode Devkit](https://github.com/KaririCode-PHP/devkit) (`kcode`) instala PHPUnit, PHPStan, Psalm, PHP-CS-Fixer e Rector em `.kcode/` (ignorado pelo git) na primeira vez que um comando precisa deles.

Instale o `kcode` uma vez (PHP 8.4+):

```bash
wget https://github.com/KaririCode-PHP/devkit/releases/latest/download/kcode.phar
chmod +x kcode.phar
sudo mv kcode.phar /usr/local/bin/kcode
```

Na raiz do repositório:

```bash
composer install          # só as dependências de runtime
kcode init                # gera .kcode/ e instala o toolchain (primeira vez)
kcode test                # PHPUnit (--coverage, --suite=Unit, qualquer opção do PHPUnit)
kcode analyse             # PHPStan e depois Psalm
kcode cs:fix              # aplica o estilo de código (--check só relata)
kcode quality             # o gate do CI: cs-fixer, PHPStan, Psalm, PHPUnit
kcode quality --docker    # o mesmo gate dentro do container PHP do kcode
```

Ajustes do projeto ficam em `devkit.php` (`kcode init --config` gera um modelo); rode `kcode init` depois de editá-lo. `kcode clean --all` remove `.kcode/`.
