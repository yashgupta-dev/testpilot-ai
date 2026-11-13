<?php

declare(strict_types=1);

namespace TestPilot\Discovery;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use TestPilot\Discovery\Internal\ClassInfo;
use TestPilot\Discovery\Internal\MethodInfo;
use TestPilot\DTO\ScanResult;
use TestPilot\Security\PathGuard;

/**
 * Discovers annotated targets by parsing source files into ASTs.
 *
 * Application code is never included, required or executed.
 */
final class ProjectScanner
{
    private readonly Parser $parser;
    private readonly AttributeReader $reader;
    private readonly ComplexityCalculator $complexity;

    /**
     * @param list<string> $exclude Glob patterns to skip.
     */
    public function __construct(
        private readonly PathGuard $guard,
        private readonly array $exclude = [],
        private readonly int $maxFileBytes = 2_000_000,
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->reader = new AttributeReader();
        $this->complexity = new ComplexityCalculator();
    }

    /**
     * @param list<string> $paths Paths relative to the project root.
     */
    public function scan(array $paths): ScanResult
    {
        $started = microtime(true);
        $files = (new FileFinder($this->guard, $this->exclude))->find($paths);

        /** @var array<string, ClassInfo> $classes */
        $classes = [];
        /** @var list<MethodInfo> $functions */
        $functions = [];
        $warnings = [];
        $errors = [];

        foreach ($files as $file) {
            $relative = $this->guard->relative($file);

            if (filesize($file) > $this->maxFileBytes) {
                $warnings[] = "{$relative}: skipped, file is larger than {$this->maxFileBytes} bytes.";
                continue;
            }

            $code = (string) file_get_contents($file);

            // Cheap pre-filter: files that never mention TestPilot cannot contain targets,
            // but they may still declare parent classes or traits, so keep class-likes.
            try {
                $ast = $this->parser->parse($code) ?? [];
            } catch (Error $e) {
                $errors[] = "{$relative}: {$e->getMessage()}";
                continue;
            }

            // Names must be fully resolved for the whole file before collecting: the collector reads
            // method attributes while visiting their class, before the resolver would reach them.
            $resolver = new NodeTraverser();
            $resolver->addVisitor(new NameResolver());
            $ast = $resolver->traverse($ast);

            $collector = new DeclarationCollector($code, $relative, $this->reader, $this->complexity);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($collector);
            $traverser->traverse($ast);

            foreach ($collector->classes as $class) {
                $classes[strtolower($class->name)] = $class;
            }
            array_push($functions, ...$collector->functions);
            array_push($warnings, ...$collector->warnings);
        }

        $builder = new TargetBuilder($classes);
        $targets = $builder->build($functions);
        array_push($warnings, ...$builder->warnings());

        return new ScanResult(
            targets: $targets,
            warnings: array_values(array_unique($warnings)),
            errors: $errors,
            filesScanned: count($files),
            durationMs: round((microtime(true) - $started) * 1000, 2),
        );
    }
}
