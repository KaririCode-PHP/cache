<?php

declare(strict_types=1);

/**
 * Spec V4.3.3 §32 supply chain, "SBOM generated": writes sbom.cdx.json
 * (CycloneDX 1.5) from composer.json and composer.lock. Runtime packages
 * have scope "required"; development packages "excluded" (never installed
 * by consumers).
 *
 * Usage: php tools/sbom.php   (after composer install)
 */

$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents("{$root}/composer.json"), true, flags: JSON_THROW_ON_ERROR);
$lock = json_decode((string) file_get_contents("{$root}/composer.lock"), true, flags: JSON_THROW_ON_ERROR);

if (!is_array($manifest) || !is_array($lock)) {
    fwrite(STDERR, "sbom: composer.json or composer.lock is not an object\n");
    exit(1);
}

$component = static fn(array $package, string $scope): array => [
    'type' => 'library',
    'bom-ref' => "pkg:composer/{$package['name']}@{$package['version']}",
    'name' => $package['name'],
    'version' => $package['version'],
    'purl' => "pkg:composer/{$package['name']}@{$package['version']}",
    'scope' => $scope,
];

$sbom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.5',
    'version' => 1,
    'metadata' => [
        'component' => ['type' => 'library', 'name' => $manifest['name'], 'bom-ref' => "pkg:composer/{$manifest['name']}"],
    ],
    'components' => [
        ...array_map(static fn(array $package): array => $component($package, 'required'), $lock['packages'] ?? []),
        ...array_map(static fn(array $package): array => $component($package, 'excluded'), $lock['packages-dev'] ?? []),
    ],
];

file_put_contents("{$root}/sbom.cdx.json", json_encode($sbom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
printf("sbom: %d runtime, %d development components -> sbom.cdx.json\n", count($lock['packages'] ?? []), count($lock['packages-dev'] ?? []));
