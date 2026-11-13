<?php

declare(strict_types=1);

namespace TestPilot\Generation;

use TestPilot\Exceptions\InvalidAiResponseException;

/** Extracts exactly one PHP file from a model reply. */
final class CodeExtractor
{
    public static function php(string $content): string
    {
        $blocks = [];
        if (preg_match_all('/```(?:php)?[ \t]*\r?\n(.*?)```/s', $content, $matches) > 0) {
            $blocks = array_values(array_filter($matches[1], static fn (string $b): bool => str_contains($b, '<?php')));
        }

        if (count($blocks) > 1) {
            throw new InvalidAiResponseException('The AI returned more than one PHP file', [], $content);
        }

        $code = $blocks[0] ?? (str_starts_with(ltrim($content), '<?php') ? ltrim($content) : null);

        if ($code === null) {
            throw new InvalidAiResponseException('The AI response does not contain a PHP file', [], $content);
        }

        $code = rtrim(str_replace("\r\n", "\n", $code)) . "\n";

        if (! str_starts_with(ltrim($code), '<?php')) {
            throw new InvalidAiResponseException('Generated file must start with <?php', [], $content);
        }

        return ltrim($code);
    }
}
