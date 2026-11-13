<?php

declare(strict_types=1);

namespace TestPilot\Discovery;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use TestPilot\Discovery\Internal\ClassInfo;
use TestPilot\Discovery\Internal\MethodInfo;
use TestPilot\DTO\ParameterInfo;

/**
 * Collects class-likes and functions from one file. Runs after NameResolver, so names are fully qualified.
 *
 * Anonymous classes are skipped: they cannot be referenced from a test, so they are tested through
 * the public code that creates them.
 */
final class DeclarationCollector extends NodeVisitorAbstract
{
    /** @var list<ClassInfo> */
    public array $classes = [];

    /** @var list<MethodInfo> */
    public array $functions = [];

    /** @var list<string> */
    public array $warnings = [];

    public function __construct(
        private readonly string $code,
        private readonly string $relativeFile,
        private readonly AttributeReader $reader,
        private readonly ComplexityCalculator $complexity,
    ) {
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Stmt\ClassLike) {
            if ($node instanceof Stmt\Class_ && $node->isAnonymous()) {
                return null;
            }
            $this->collectClass($node);
        }

        if ($node instanceof Stmt\Function_) {
            $name = $node->namespacedName?->toString() ?? $node->name->toString();
            $this->functions[] = $this->methodInfo($node, $name, 'public', false, false, $name, 'function');
        }

        return null;
    }

    private function collectClass(Stmt\ClassLike $node): void
    {
        $name = $node->namespacedName?->toString() ?? (string) $node->name;
        $kind = match (true) {
            $node instanceof Stmt\Trait_ => 'trait',
            $node instanceof Stmt\Interface_ => 'interface',
            $node instanceof Stmt\Enum_ => 'enum',
            default => 'class',
        };

        $traits = [];
        $methods = [];
        $properties = [];

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $traits[] = $trait->toString();
                }
            }

            if ($stmt instanceof Stmt\ClassMethod) {
                $visibility = $stmt->isPrivate() ? 'private' : ($stmt->isProtected() ? 'protected' : 'public');
                $info = $this->methodInfo(
                    $stmt,
                    $stmt->name->toString(),
                    $visibility,
                    $stmt->isStatic(),
                    $stmt->isAbstract() || $kind === 'interface',
                    $name,
                    'method',
                );
                $methods[strtolower($info->name)] = $info;
            }

            if ($stmt instanceof Stmt\Property) {
                $properties[] = trim($this->slice($stmt->getStartFilePos(), $stmt->getEndFilePos()));
            }
        }

        $annotations = $this->reader->read($node->attrGroups, 'class');
        foreach ($annotations as $annotation) {
            foreach ($annotation->warnings as $warning) {
                $this->warnings[] = "{$this->relativeFile}:{$annotation->line} {$warning}";
            }
        }

        $isAbstract = $node instanceof Stmt\Class_ && $node->isAbstract();
        $parent = $node instanceof Stmt\Class_ && $node->extends !== null ? $node->extends->toString() : null;

        $this->classes[] = new ClassInfo(
            name: $name,
            kind: $kind,
            isAbstract: $isAbstract,
            parent: $parent,
            traits: $traits,
            annotations: $annotations,
            methods: $methods,
            properties: $properties,
            header: $this->classHeader($node, $kind, $name),
            file: $this->relativeFile,
            line: $node->getStartLine(),
        );
    }

    private function methodInfo(
        Stmt\ClassMethod|Stmt\Function_ $node,
        string $name,
        string $visibility,
        bool $isStatic,
        bool $isAbstract,
        string $declaringClass,
        string $scope,
    ): MethodInfo {
        $annotations = $this->reader->read($node->attrGroups, $scope);
        foreach ($annotations as $annotation) {
            foreach ($annotation->warnings as $warning) {
                $this->warnings[] = "{$this->relativeFile}:{$annotation->line} {$warning}";
            }
        }

        $parameters = [];
        foreach ($node->params as $param) {
            $variable = $param->var;
            $paramName = $variable instanceof Node\Expr\Variable && is_string($variable->name) ? $variable->name : 'param';
            $parameters[] = new ParameterInfo(
                name: $paramName,
                type: $param->type !== null ? $this->nodeText($param->type) : null,
                hasDefault: $param->default !== null,
                default: $param->default !== null ? $this->nodeText($param->default) : null,
                variadic: $param->variadic,
                byRef: $param->byRef,
                class: $this->resolvedClass($param->type),
            );
        }

        $source = $this->slice($node->getStartFilePos(), $node->getEndFilePos());
        $doc = $node->getDocComment();
        if ($doc !== null) {
            $source = $doc->getText() . "\n" . $source;
        }

        return new MethodInfo(
            name: $name,
            visibility: $visibility,
            isStatic: $isStatic,
            isAbstract: $isAbstract,
            startLine: $node->getStartLine(),
            endLine: $node->getEndLine(),
            signature: $this->signature($node),
            parameters: $parameters,
            returnType: $node->returnType !== null ? $this->nodeText($node->returnType) : null,
            source: $source,
            complexity: $this->complexity->calculate($node),
            annotations: $annotations,
            file: $this->relativeFile,
            declaringClass: $declaringClass,
        );
    }

    /** The declaration up to (not including) the body, without attributes. */
    private function signature(Stmt\ClassMethod|Stmt\Function_ $node): string
    {
        $start = $node->getStartFilePos();
        if ($node->attrGroups !== []) {
            $start = end($node->attrGroups)->getEndFilePos() + 1;
        }

        $searchFrom = $node->name->getEndFilePos();
        if ($node->params !== []) {
            $searchFrom = end($node->params)->getEndFilePos();
        }
        if ($node->returnType !== null) {
            $searchFrom = $node->returnType->getEndFilePos();
        }

        $stop = $node->getStmts() === null
            ? strpos($this->code, ';', $searchFrom)
            : strpos($this->code, '{', $searchFrom);

        $end = $stop === false ? $node->getEndFilePos() + 1 : $stop;
        $text = substr($this->code, $start, $end - $start);

        return (string) preg_replace('/\s+/', ' ', trim($text));
    }

    private function classHeader(Stmt\ClassLike $node, string $kind, string $name): string
    {
        $short = $node->name?->toString() ?? $name;
        $header = ($node instanceof Stmt\Class_ && $node->isAbstract() ? 'abstract ' : '')
            . ($node instanceof Stmt\Class_ && $node->isFinal() ? 'final ' : '')
            . $kind . ' ' . $short;

        if ($node instanceof Stmt\Class_ && $node->extends !== null) {
            $header .= ' extends \\' . $node->extends->toString();
        }
        if ($node instanceof Stmt\Class_ && $node->implements !== []) {
            $header .= ' implements ' . implode(', ', array_map(static fn ($n): string => '\\' . $n->toString(), $node->implements));
        }

        return $header;
    }

    private function resolvedClass(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }

        if (! $type instanceof Node\Name) {
            return null; // builtin (Identifier), union or intersection types
        }

        $name = $type->toString();

        return in_array(strtolower($name), ['self', 'static', 'parent'], true) ? null : ltrim($name, '\\');
    }

    private function nodeText(Node $node): string
    {
        return trim($this->slice($node->getStartFilePos(), $node->getEndFilePos()));
    }

    private function slice(int $start, int $end): string
    {
        if ($start < 0 || $end < $start) {
            return '';
        }

        return substr($this->code, $start, $end - $start + 1);
    }
}
