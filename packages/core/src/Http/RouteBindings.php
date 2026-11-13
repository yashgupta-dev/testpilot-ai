<?php

declare(strict_types=1);

namespace TestPilot\Http;

/**
 * How to fill a route's URL parameters inside a generated test.
 *
 * Each parameter is one of:
 *  - model:  an Eloquent model created with its factory (optionally ->for($parent) for scoped routes);
 *            the URL uses $model->getRouteKey(), or $model->{field} for {post:slug}.
 *  - enum:   a backed enum; the URL uses its first case.
 *  - scalar: a fixed placeholder (e.g. "1"). Fine for validation and throttling, which run before
 *            the controller body looks anything up.
 */
final class RouteBindings
{
    /** @param list<array{name: string, kind: string, class?: string|null, field?: string|null, parent?: string|null, value?: string|null}> $parameters */
    public function __construct(
        public readonly string $uri,
        public readonly array $parameters,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['uri'], array_values((array) ($data['parameters'] ?? [])));
    }

    /** @return list<string> Names of the {parameters} in the URI, in order. */
    public static function parameterNames(string $uri): array
    {
        preg_match_all('/\{([^}:?]+)(?::[^}?]+)?\??\}/', $uri, $matches);

        return $matches[1];
    }

    public function isEmpty(): bool
    {
        return $this->parameters === [];
    }

    public function usesDatabase(): bool
    {
        foreach ($this->parameters as $p) {
            if ($p['kind'] === 'model') {
                return true;
            }
        }

        return false;
    }

    /**
     * Statements that create the records, ending with `$this->testpilotUri = …;`.
     *
     * @return list<string>
     */
    public function setupLines(string $indent = '    '): array
    {
        $lines = [];
        foreach ($this->parameters as $p) {
            if ($p['kind'] !== 'model') {
                continue;
            }
            $class = '\\' . ltrim((string) $p['class'], '\\');
            $for = ! empty($p['parent']) ? '->for($' . $p['parent'] . ')' : '';
            $lines[] = "{$indent}\${$p['name']} = {$class}::factory(){$for}->create();";
        }
        $lines[] = "{$indent}\$this->testpilotUri = {$this->uriExpression()};";

        return $lines;
    }

    /** PHP expression that builds the URL from the variables created in setupLines(). */
    public function uriExpression(): string
    {
        $byName = [];
        foreach ($this->parameters as $p) {
            $byName[$p['name']] = $p;
        }

        $parts = preg_split('/(\{[^}]+\})/', $this->uri, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $pieces = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{([^}:?]+)(?::[^}?]+)?\??\}$/', $part, $m) !== 1) {
                $pieces[] = var_export($part, true);
                continue;
            }
            $p = $byName[$m[1]] ?? ['kind' => 'scalar', 'value' => '1'];
            $pieces[] = match ($p['kind']) {
                'model' => empty($p['field']) ? "\${$m[1]}->getRouteKey()" : "\${$m[1]}->{$p['field']}",
                'enum' => '\\' . ltrim((string) $p['class'], '\\') . '::cases()[0]->value',
                default => var_export((string) ($p['value'] ?? '1'), true),
            };
        }

        return implode(' . ', $pieces) ?: "'/'";
    }

    /** Human description for the dashboard, e.g. "{order} → Order::factory()". */
    public function describe(): array
    {
        return array_map(static function (array $p): string {
            $short = isset($p['class']) ? substr((string) $p['class'], (int) strrpos((string) $p['class'], '\\') + 1) : '';

            return '{' . $p['name'] . (empty($p['field']) ? '' : ':' . $p['field']) . '} → ' . match ($p['kind']) {
                'model' => "{$short}::factory()" . (empty($p['parent']) ? '' : "->for(\${$p['parent']})") . '->create()',
                'enum' => "{$short}::cases()[0]",
                default => var_export((string) ($p['value'] ?? '1'), true) . ' (placeholder)',
            };
        }, $this->parameters);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['uri' => $this->uri, 'parameters' => $this->parameters, 'description' => $this->describe()];
    }
}
