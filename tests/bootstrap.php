<?php

declare(strict_types=1);

require \dirname(__DIR__) . '/vendor/autoload.php';

// psr/cache and psr/simple-cache, needed by the bridge tests, come with the
// toolchain kcode installs (devkit.php, ADR-015). The project's autoloader is
// registered first, so its packages win over any copy in .kcode/.
require \dirname(__DIR__) . '/.kcode/vendor/autoload.php';
