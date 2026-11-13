<?php

declare(strict_types=1);

namespace TestPilot\Ai;

use TestPilot\Exceptions\ConfigurationException;

/**
 * Versioned prompt templates. Every generated test records the prompt version that produced it,
 * so a prompt change can be traced to changes in test quality.
 */
final class PromptRegistry
{
    public const VERSION = 'v1';

    public function __construct(private readonly string $directory = __DIR__ . '/Prompts')
    {
    }

    /** @param array<string, string> $variables */
    public function render(string $name, array $variables = [], string $version = self::VERSION): string
    {
        $path = $this->directory . '/' . $version . '/' . $name . '.md';

        if (! is_file($path)) {
            throw new ConfigurationException("Prompt template not found: {$version}/{$name}");
        }

        $template = (string) file_get_contents($path);
        $rendered = (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static fn (array $m): string => $variables[$m[1]] ?? '', $template);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $rendered));
    }

    public function version(): string
    {
        return self::VERSION;
    }
}
