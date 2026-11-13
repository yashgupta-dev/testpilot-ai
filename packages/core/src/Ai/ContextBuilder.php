<?php

declare(strict_types=1);

namespace TestPilot\Ai;

use TestPilot\Configuration\ResolvedConfig;
use TestPilot\DTO\TestTarget;

/**
 * Builds the smallest useful context for one target: its source, the outline of its class
 * (signatures only — other method bodies are never sent) and project testing conventions.
 */
final class ContextBuilder
{
    /**
     * @param list<string> $conventions Free-text notes about the project, e.g. "Laravel 12, factories available".
     */
    public function __construct(
        private readonly array $conventions = [],
        private readonly int $maxSourceChars = 12_000,
    ) {
    }

    public function build(TestTarget $target, ResolvedConfig $config): string
    {
        $source = $target->source;
        if (strlen($source) > $this->maxSourceChars) {
            $source = substr($source, 0, $this->maxSourceChars) . "\n// … truncated by TestPilot (source too long)";
        }

        $parts = [];
        $parts[] = "## Target\n"
            . "Key: {$target->key}\n"
            . "Kind: {$target->type->value}" . ($target->isStatic ? ' (static)' : '') . "\n"
            . "File: {$target->location->file} (lines {$target->location->startLine}–{$target->location->endLine})\n"
            . ($target->isInherited() ? "Declared in: {$target->declaredIn}\n" : '')
            . "Signature: {$target->signature}\n"
            . "Cyclomatic complexity: {$target->complexity}";

        if ($target->namespace !== null) {
            $parts[] = "Namespace: {$target->namespace}";
        }

        if ($target->classSkeleton !== null) {
            $parts[] = "## Class outline (signatures only)\n```php\n{$target->classSkeleton}\n```";
        }

        $parts[] = "## Source of the target (line numbers start at {$target->location->startLine})\n```php\n{$source}\n```";

        $modules = array_map(static fn ($m): string => $m->value, $config->activeModules());
        $parts[] = "## TestPilot configuration\nModules: " . implode(', ', $modules)
            . ($config->area !== null ? "\nBusiness area: {$config->area}" : '')
            . ($config->criticality !== null ? "\nCriticality: {$config->criticality}" : '');

        if ($this->conventions !== []) {
            $parts[] = "## Project conventions\n- " . implode("\n- ", $this->conventions);
        }

        return implode("\n\n", $parts);
    }
}
