<?php

declare(strict_types=1);

namespace TestPilot\Support;

/** Exports PHP values as short, readable literals for generated test code. */
final class PhpLiteral
{
    public static function export(mixed $value, int $indent = 0): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            $value === PHP_INT_MAX => 'PHP_INT_MAX',
            $value === PHP_INT_MIN => 'PHP_INT_MIN',
            is_int($value) => (string) $value,
            is_float($value) => self::float($value),
            is_string($value) => self::string($value),
            is_array($value) => self::array($value, $indent),
            default => 'null',
        };
    }

    private static function float(float $value): string
    {
        $text = var_export($value, true);

        return str_contains($text, '.') || str_contains($text, 'E') ? $text : $text . '.0';
    }

    private static function string(string $value): string
    {
        $length = strlen($value);
        if ($length > 40 && $value === str_repeat($value[0], $length)) {
            return 'str_repeat(' . var_export($value[0], true) . ', ' . $length . ')';
        }

        return var_export($value, true);
    }

    /** @param array<mixed> $value */
    private static function array(array $value, int $indent): string
    {
        if ($value === []) {
            return '[]';
        }

        $isList = array_is_list($value);
        $inline = array_map(
            static fn ($k, $v): string => ($isList ? '' : var_export($k, true) . ' => ') . self::export($v, $indent),
            array_keys($value),
            $value,
        );
        $line = '[' . implode(', ', $inline) . ']';

        if (strlen($line) <= 80 && ! str_contains($line, "\n")) {
            return $line;
        }

        $pad = str_repeat('    ', $indent + 1);
        $rows = array_map(
            static fn ($k, $v): string => $pad . ($isList ? '' : var_export($k, true) . ' => ') . self::export($v, $indent + 1) . ',',
            array_keys($value),
            $value,
        );

        return "[\n" . implode("\n", $rows) . "\n" . str_repeat('    ', $indent) . ']';
    }
}
