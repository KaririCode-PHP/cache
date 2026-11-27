<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Conformance;

use PHPUnit\Framework\TestCase;

/**
 * Structural rules of ARFA 1.3 and the KaririCode Code Quality
 * Specification V4.x, checked on the source tree.
 */
final class ArfaConformanceTest extends TestCase
{
    /** Spec V4.3.3 §17: C1 role, ARFA and Spec classification, labelled C2–C5 sections. */
    private const array REQUIRED_DOC_TAGS = [
        'ARFA 1.3' => '/ARFA 1\.3/',
        'Spec V4' => '/Spec V4/',
        'Preconditions (C2)' => '/\* Preconditions: \S/',
        'Thread-safety (C2)' => '/\* Thread-safety: \S/',
        'Side effects (C3)' => '/\* Side effects: \S/',
        'Example (C4)' => '/\* Example:\n \*     \S/',
        'References (C5)' => '/\* References: \S/',
        '@see URL' => '/@see\s+https?:\/\//',
    ];

    private const array FORBIDDEN_PATTERNS = [
        'toArray()' => '/function\s+toArray\s*\(/',
        'fromArray()' => '/function\s+fromArray\s*\(/',
        'jsonSerialize()' => '/function\s+jsonSerialize\s*\(/',
        'tautological doc' => '/\*\s+(Gets the|Sets the|Executes the|Represents a)\b/',
        'inheritDoc' => '/inheritdoc/i',
        'generic exception' => '/new\s+\\\\(RuntimeException|InvalidArgumentException|LogicException|Exception)\s*\(/',
        'error suppression' => '/(?<![\w\/*])@(?:unlink|mkdir|rmdir|file_|fopen|unserialize|chmod|rename)\b/',
        'redundant doc tags (V4.3.3)' => '/@(package|author|license|since)\b/',
    ];

    /** Classes allowed to be extended. */
    private const array NON_FINAL = [];

    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function testEveryFileDeclaresStrictTypesAndOneType(): void
    {
        foreach (self::sourceFiles() as $file) {
            $code = (string) file_get_contents($file);

            self::assertStringContainsString('declare(strict_types=1);', $code, $file);
            self::assertSame(1, preg_match_all('/^(?:final |abstract |readonly )*(?:class|interface|trait|enum) \w+/m', $code), "{$file}: one named type per file");
        }
    }

    public function testEveryFileCarriesTheRequiredDocblockTags(): void
    {
        foreach (self::sourceFiles() as $file) {
            $code = (string) file_get_contents($file);

            foreach (self::REQUIRED_DOC_TAGS as $label => $pattern) {
                self::assertMatchesRegularExpression($pattern, $code, "{$file}: missing {$label}");
            }
        }
    }

    public function testNoForbiddenPatterns(): void
    {
        foreach (self::sourceFiles() as $file) {
            $code = (string) file_get_contents($file);

            foreach (self::FORBIDDEN_PATTERNS as $label => $pattern) {
                self::assertDoesNotMatchRegularExpression($pattern, $code, "{$file}: {$label}");
            }
        }
    }

    public function testConcreteClassesAreFinalAndEnumsArePure(): void
    {
        foreach (self::sourceFiles() as $file) {
            $code = (string) file_get_contents($file);

            if (1 !== preg_match('/^namespace ([^;]+);/m', $code, $ns)
                || 1 !== preg_match('/^((?:final |abstract |readonly )*)(class|interface|trait|enum) (\w+)(?::\s*\w+)?/m', $code, $m)) {
                continue;
            }

            $fqcn = $ns[1] . '\\' . $m[3];

            if ('class' === $m[2] && !in_array($fqcn, self::NON_FINAL, true)) {
                self::assertStringContainsString('final', $m[1], "{$fqcn} must be final");
            }

            if ('enum' === $m[2]) {
                self::assertDoesNotMatchRegularExpression('/^enum \w+\s*:/m', $code, "{$fqcn}: backed enum needs a justification (E-003)");
            }
        }
    }

    public function testSectionMarkersAreExactly72Characters(): void
    {
        $root = dirname(__DIR__, 2);
        $checked = 0;

        foreach (['src', 'tests', 'benchmark'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}"));

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                    continue;
                }

                foreach (file($file->getPathname()) ?: [] as $number => $line) {
                    $marker = trim($line);

                    if (str_starts_with($marker, '// ──')) {
                        ++$checked;
                        self::assertSame(72, mb_strlen($marker), $file->getPathname() . ':' . ($number + 1));
                    }
                }
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    public function testComposerDeclaresConformanceAndCouplesOnlyThroughContractAndPsr(): void
    {
        /** @var array{require: array<string, string>, extra: array{kariricode: array{spec: string, arfa: array{version: string, level: int}, asvs: array{version: string, level: int}, "error-code-range": string}}} $composer */
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        // §31: runtime coupling only through kariricode/contract and PSR packages.
        foreach (array_keys($composer['require']) as $package) {
            self::assertMatchesRegularExpression('#^(php|kariricode/contract|psr/[a-z-]+)$#', $package);
        }

        self::assertSame('4.3.3', $composer['extra']['kariricode']['spec']);
        self::assertSame(['version' => '1.3', 'level' => 1], $composer['extra']['kariricode']['arfa']);
        self::assertSame('5.0', $composer['extra']['kariricode']['asvs']['version']);
        self::assertSame('8600–8699', $composer['extra']['kariricode']['error-code-range']);
    }
}
