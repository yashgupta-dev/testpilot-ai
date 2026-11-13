<?php

declare(strict_types=1);

namespace TestPilot\Generation;

/**
 * Project-specific conventions passed to the code generator.
 */
final class GenerationOptions
{
    public function __construct(
        /** Base TestCase to bind with uses(), e.g. "Tests\\TestCase" for Laravel. Null for plain PHP. */
        public readonly ?string $testCaseClass = null,
        /** Add mutates(Class::class) so `pest --mutate` targets the class under test. */
        public readonly bool $declareMutates = true,
    ) {
    }

    public function testCaseInstruction(): string
    {
        return $this->testCaseClass === null
            ? 'Do not call uses() — this is a plain PHP project; tests run on Pest\'s default test case.'
            : "Bind the framework test case at the top of the file with: uses(\\{$this->testCaseClass}::class);";
    }

    public function mutatesInstruction(?string $class): string
    {
        return $this->declareMutates && $class !== null
            ? "Add mutates(\\{$class}::class); after the use statements so mutation testing targets this class."
            : 'Do not call mutates() or covers().';
    }
}
