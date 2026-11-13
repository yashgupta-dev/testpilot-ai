<?php

declare(strict_types=1);

namespace TestPilot\Fuzz;

use Random\Engine\Mt19937;
use Random\Randomizer;
use TestPilot\DTO\ParameterInfo;
use TestPilot\DTO\TestTarget;
use TestPilot\Support\PhpLiteral;

/**
 * Builds a deterministic fuzz dataset from parameter types and a seed.
 *
 * The values are written into the generated test file (not produced at runtime), so every run is
 * reproducible and a reviewer can read exactly which inputs were used. The same seed always yields
 * the same dataset.
 */
final class FuzzDatasetBuilder
{
    /**
     * @return array{seed: int, cases: list<array<string, mixed>>, skipped: list<string>, code: string}|null
     *         Null when no parameter has a scalar/array type that can be generated.
     */
    public function build(TestTarget $target, int $cases, ?int $seed = null): ?array
    {
        $seed ??= random_int(1, 99_999);
        $random = new Randomizer(new Mt19937($seed));

        $pools = [];
        $skipped = [];

        foreach ($target->parameters as $parameter) {
            if ($parameter->variadic || $parameter->byRef) {
                $skipped[] = $parameter->name;
                continue;
            }

            $pool = $this->pool($parameter, $random);
            if ($pool === null) {
                $skipped[] = $parameter->name;
                continue;
            }

            $pools[$parameter->name] = $pool;
        }

        if ($pools === []) {
            return null;
        }

        $rows = [];
        $seen = [];
        $attempts = 0;
        while (count($rows) < $cases && $attempts < $cases * 10) {
            $attempts++;
            $row = [];
            foreach ($pools as $name => $pool) {
                // First pass walks the boundary values in order, later passes pick at random.
                $index = count($rows) < count($pool) ? count($rows) % count($pool) : $random->getInt(0, count($pool) - 1);
                $row[$name] = $pool[$index];
            }

            $hash = serialize($row);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $rows[] = $row;
        }

        return [
            'seed' => $seed,
            'cases' => $rows,
            'skipped' => $skipped,
            'code' => $this->export($rows),
        ];
    }

    /** @return list<mixed>|null */
    private function pool(ParameterInfo $parameter, Randomizer $random): ?array
    {
        $type = ltrim(strtolower((string) $parameter->type), '?');
        $types = $type === '' ? ['mixed'] : array_map('trim', explode('|', $type));
        $pool = [];

        foreach ($types as $t) {
            $values = match ($t) {
                'int' => [0, 1, -1, 2, 100, PHP_INT_MAX, PHP_INT_MIN, $random->getInt(-10_000, 10_000), $random->getInt(1, 1_000_000)],
                'float' => [0.0, 0.1, -0.1, 1.5, -1.5, 99.99, 1.0e9, round($random->getFloat(-1000, 1000), 2), round($random->getFloat(0, 1), 4)],
                'string' => ['', ' ', 'a', 'Hello World', str_repeat('a', 256), 'ünïcødé ✓ 日本', "line1\nline2", "' OR '1'='1", '<script>alert(1)</script>', $random->getBytesFromString('abcdefghijklmnopqrstuvwxyz0123456789', 12)],
                'bool' => [true, false],
                'array', 'iterable' => [[], [0], [1, 2, 3], ['key' => 'value'], [null], array_fill(0, 50, 1)],
                'mixed' => [null, 0, '', 'text', [], true, -1, 1.5],
                'null' => [null],
                default => null,
            };

            if ($values === null) {
                return null; // objects, enums and class types are built with factories/mocks by the generator
            }

            array_push($pool, ...$values);
        }

        if ($parameter->allowsNull() && ! in_array(null, $pool, true)) {
            $pool[] = null;
        }

        return $pool;
    }

    /** @param list<array<string, mixed>> $rows */
    private function export(array $rows): string
    {
        $lines = ['['];
        foreach ($rows as $i => $row) {
            $values = array_map(static fn (mixed $v): string => PhpLiteral::export($v), array_values($row));
            $lines[] = '    \'case ' . ($i + 1) . '\' => [' . implode(', ', $values) . '],';
        }
        $lines[] = ']';

        return implode("\n", $lines);
    }
}
