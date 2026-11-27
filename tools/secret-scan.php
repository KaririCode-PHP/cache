<?php

declare(strict_types=1);

/**
 * Spec V4.3.3 §24 security gate, "secret scanning is clean": fails on
 * high-confidence credential patterns in versioned files (vendor/ excluded).
 * `--self-test` proves every pattern still matches a sample, so a broken
 * pattern cannot pass silently.
 *
 * Usage: php tools/secret-scan.php [--self-test]   — exit 1 on any finding.
 */

const PATTERNS = [
    'private key block' => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY-----/',
    'AWS access key id' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
    'GitHub token' => '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/',
    'Slack token' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',
    'Stripe live key' => '/\b[sr]k_live_[A-Za-z0-9]{20,}\b/',
    'Google API key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
    'Packagist token' => '/\bpkg_[A-Za-z0-9]{40,}\b/',
    'Redis URL with password' => '/\bredis[s]?:\/\/[^:\s\/]*:[^@\s]{8,}@/',
];

if (in_array('--self-test', $argv, true)) {
    $samples = [
        'private key block' => '-----BEGIN ' . 'RSA PRIVATE KEY-----',
        'AWS access key id' => 'AKIA' . str_repeat('A', 16),
        'GitHub token' => 'ghp_' . str_repeat('a', 36),
        'Slack token' => 'xoxb-' . str_repeat('1', 12),
        'Stripe live key' => 'sk_live_' . str_repeat('x', 24),
        'Google API key' => 'AIza' . str_repeat('B', 35),
        'Packagist token' => 'pkg_' . str_repeat('c', 40),
        'Redis URL with password' => 'redis://default:' . str_repeat('p', 12) . '@cache:6379',
    ];

    foreach (PATTERNS as $label => $pattern) {
        if (1 !== preg_match($pattern, $samples[$label])) {
            fwrite(STDERR, "secret-scan self-test: pattern '{$label}' no longer matches its sample\n");
            exit(1);
        }
    }

    echo 'secret-scan self-test: ' . count(PATTERNS) . " patterns ok\n";
    exit(0);
}

$root = dirname(__DIR__);
$files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    static fn(SplFileInfo $file): bool => !in_array($file->getFilename(), ['vendor', '.git', '.phpunit.cache'], true),
));
$findings = [];

foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getSize() > 1_000_000 || str_ends_with($file->getPathname(), 'tools/secret-scan.php')) {
        continue;
    }

    $text = (string) file_get_contents($file->getPathname());

    foreach (PATTERNS as $label => $pattern) {
        if (1 === preg_match($pattern, $text)) {
            $findings[] = substr($file->getPathname(), strlen($root) + 1) . ": {$label}";
        }
    }
}

foreach ($findings as $finding) {
    fwrite(STDERR, "secret: {$finding}\n");
}

echo [] === $findings ? "secret-scan: clean\n" : 'secret-scan: ' . count($findings) . " finding(s)\n";
exit([] === $findings ? 0 : 1);
