<?php

declare(strict_types=1);

/**
 * KaririCode Spec V4.3.3 budgets, measured as KC Console and KC RateLimiter
 * do (cognitive complexity <= 15 is enforced by PHPStan through
 * tomasvotruba/cognitive-complexity):
 *
 *   cyclomatic complexity / method  <= 10  a switch or match counts as one
 *                                          decision; closures count toward
 *                                          their method (§13)
 *   nesting depth / method          <= 3   (§13)
 *   parameters / method             <= 4   exempt: constructors of readonly
 *                                          classes whose parameters are all
 *                                          promoted — the parameter object (§13)
 *   positional arguments / call     <= 2   method, static and constructor
 *                                          calls with 3+ arguments use names (§30)
 *   public methods / class          <= 10  soft: reported, never failing
 *
 * Usage: php tools/complexity.php src   — exit 1 on any hard violation.
 */

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

// nikic/php-parser comes with the toolchain kcode installs (ADR-015).
require dirname(__DIR__) . '/.kcode/vendor/autoload.php';

const MAX_CYCLOMATIC = 10;
const MAX_NESTING = 3;
const MAX_PARAMETERS = 4;
const MAX_POSITIONAL = 2;
const MAX_PUBLIC = 10;

$decisions = [
    Node\Stmt\If_::class, Node\Stmt\ElseIf_::class, Node\Stmt\For_::class, Node\Stmt\Foreach_::class,
    Node\Stmt\While_::class, Node\Stmt\Do_::class, Node\Stmt\Catch_::class, Node\Expr\Ternary::class,
    Node\Expr\BinaryOp\Coalesce::class, Node\Expr\AssignOp\Coalesce::class, Node\Expr\BinaryOp\BooleanAnd::class,
    Node\Expr\BinaryOp\BooleanOr::class, Node\Expr\BinaryOp\LogicalAnd::class, Node\Expr\BinaryOp\LogicalOr::class,
    Node\Expr\BinaryOp\LogicalXor::class, Node\Stmt\Switch_::class, Node\Expr\Match_::class,
];
$nesters = [
    Node\Stmt\If_::class, Node\Stmt\For_::class, Node\Stmt\Foreach_::class, Node\Stmt\While_::class,
    Node\Stmt\Do_::class, Node\Stmt\Switch_::class, Node\Stmt\TryCatch::class, Node\Expr\Match_::class,
];
$calls = [Node\Expr\MethodCall::class, Node\Expr\NullsafeMethodCall::class, Node\Expr\StaticCall::class, Node\Expr\New_::class];

/**
 * Deepest stack of control structures inside one method.
 */
final class NestingVisitor extends PhpParser\NodeVisitorAbstract
{
    public int $max = 0;

    private int $depth = 0;

    /**
     * @param list<class-string<Node>> $nesters
     */
    public function __construct(private readonly array $nesters) {}

    public function enterNode(Node $node): null
    {
        if (in_array($node::class, $this->nesters, true)) {
            $this->max = max($this->max, ++$this->depth);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (in_array($node::class, $this->nesters, true)) {
            --$this->depth;
        }

        return null;
    }
}

/**
 * @param array<Node>              $statements
 * @param list<class-string<Node>> $nesters
 */
function nestingDepth(array $statements, array $nesters): int
{
    $visitor = new NestingVisitor($nesters);
    $traverser = new PhpParser\NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($statements);

    return $visitor->max;
}

$parser = (new ParserFactory())->createForHostVersion();
$finder = new NodeFinder();
$violations = [];
$warnings = [];
$measured = 0;
$root = $argv[1] ?? 'src';

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
        continue;
    }

    $path = $file->getPathname();
    $ast = $parser->parse((string) file_get_contents($path)) ?? [];

    foreach ($finder->findInstanceOf($ast, Node\Expr\CallLike::class) as $call) {
        if (!in_array($call::class, $calls, true) || $call->isFirstClassCallable()) {
            continue;
        }

        $args = $call->getArgs();

        if (count($args) > MAX_POSITIONAL && array_all($args, static fn(Node\Arg $arg): bool => null === $arg->name && !$arg->unpack)) {
            $violations[] = "{$path}:{$call->getStartLine()} call with " . count($args) . ' positional arguments (§30)';
        }
    }

    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class) {
        $name = $class->name?->toString() ?? '(anonymous)';
        $public = 0;

        foreach ($class->getMethods() as $method) {
            $measured++;
            $where = "{$path}: {$name}::{$method->name}()";
            $public += $method->isPublic() && '__construct' !== $method->name->toString() ? 1 : 0;

            $cyclomatic = 1 + count($finder->find((array) $method->getStmts(), static fn(Node $node): bool => in_array($node::class, $decisions, true)));
            if ($cyclomatic > MAX_CYCLOMATIC) {
                $violations[] = "{$where} cyclomatic {$cyclomatic} > " . MAX_CYCLOMATIC . ' (§13)';
            }

            $depth = nestingDepth($method->getStmts() ?? [], $nesters);
            if ($depth > MAX_NESTING) {
                $violations[] = "{$where} nesting {$depth} > " . MAX_NESTING . ' (§13)';
            }

            $parameterObject = '__construct' === $method->name->toString()
                && $class instanceof Node\Stmt\Class_ && $class->isReadonly()
                && array_all($method->params, static fn(Node\Param $param): bool => 0 !== $param->flags);
            if (count($method->params) > MAX_PARAMETERS && !$parameterObject) {
                $violations[] = "{$where} parameters " . count($method->params) . ' > ' . MAX_PARAMETERS . ' (§13)';
            }
        }

        if ($public > MAX_PUBLIC) {
            $warnings[] = "{$path}: {$name} has {$public} public methods (soft budget " . MAX_PUBLIC . ')';
        }
    }
}

foreach ($warnings as $warning) {
    fwrite(STDERR, "warning: {$warning}\n");
}

foreach ($violations as $violation) {
    fwrite(STDERR, "violation: {$violation}\n");
}

echo [] === $violations ? "complexity: {$measured} methods within budgets\n" : 'complexity: ' . count($violations) . " violation(s)\n";
exit([] === $violations ? 0 : 1);
