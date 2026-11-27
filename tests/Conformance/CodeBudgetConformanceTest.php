<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Conformance;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Enforces the measurable rules of the KaririCode Specification V4.3.3:
 * §13 budgets and §30 named arguments (through tools/complexity.php),
 * §18 banned names, §29.2 magic methods and mutable static state.
 *
 * Interpretation recorded in CONTRIBUTING: constructors of classes in
 * ValueObject/ and Configuration/ are exempt from the four-parameter
 * budget, because those classes are the parameter objects the rule asks for.
 */
final class CodeBudgetConformanceTest extends TestCase
{
    /** Magic methods allowed besides __construct, with the reason. */
    private const array ALLOWED_MAGIC = [
        'Psr6Bridge::__destruct' => 'PSR-6: deferred items must eventually be persisted',
    ];

    /** @var list<array{file: string, class: Node\Stmt\ClassLike}>|null */
    private static ?array $classes = null;

    public function testNoBannedTypeNames(): void
    {
        foreach (self::classes() as ['file' => $file, 'class' => $class]) {
            $name = (string) $class->name;

            self::assertDoesNotMatchRegularExpression('/(Manager|Helper|Utils?|Factory)$/', $name, "{$file}: §18 bans vague names and factories that only wrap new");

            if ($class instanceof Node\Stmt\Interface_) {
                self::assertStringEndsNotWith('Interface', $name, "{$file}: §18 bans the Interface suffix");
            }
        }
    }

    public function testOnlyAllowedMagicMethods(): void
    {
        foreach (self::classes() as ['file' => $file, 'class' => $class]) {
            foreach ($class->getMethods() as $method) {
                $name = $method->name->toString();

                if (!str_starts_with($name, '__') || '__construct' === $name) {
                    continue;
                }

                self::assertArrayHasKey($class->name . '::' . $name, self::ALLOWED_MAGIC, "{$file}: §29.2 bans magic methods");
            }
        }
    }

    public function testNoStaticProperties(): void
    {
        foreach (self::classes() as ['file' => $file, 'class' => $class]) {
            foreach ($class->getProperties() as $property) {
                self::assertFalse($property->isStatic(), "{$file}: §12/§29.2 ban mutable static state");
            }
        }
    }

    public function testMethodBudgetsAndNamedArguments(): void
    {
        $root = dirname(__DIR__, 2);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/tools/complexity.php") . ' ' . escapeshellarg("{$root}/src") . ' 2>&1', $output, $exit);

        self::assertSame(0, $exit, implode("\n", $output));
        self::assertMatchesRegularExpression('/complexity: \d{3,} methods within budgets/', implode("\n", $output));
    }

    /**
     * @return list<array{file: string, class: Node\Stmt\ClassLike}>
     */
    private static function classes(): array
    {
        if (null !== self::$classes) {
            return self::$classes;
        }

        $parser = (new ParserFactory())->createForHostVersion();
        $finder = new NodeFinder();
        $classes = [];
        $root = dirname(__DIR__, 2) . '/src';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            foreach ($finder->findInstanceOf($parser->parse($contents) ?? [], Node\Stmt\ClassLike::class) as $class) {
                if (null !== $class->name) {
                    $classes[] = ['file' => substr($file->getPathname(), strlen($root)), 'class' => $class];
                }
            }
        }

        return self::$classes = $classes;
    }

}
