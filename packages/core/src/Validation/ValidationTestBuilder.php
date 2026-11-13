<?php

declare(strict_types=1);

namespace TestPilot\Validation;

use TestPilot\Http\RouteBindings;
use TestPilot\Support\PhpLiteral;

/**
 * Writes the Pest file for approved validation scenarios. Deterministic: the same plan always
 * produces the same file, so reviewers see exactly what will run.
 */
final class ValidationTestBuilder
{
    /** laravel (422 + JSON errors), status (your own status code), redirect_errors (302 + session errors). */
    private string $mode = 'laravel';
    private int $status = 422;

    /**
     * @param array{
     *     request_class?: string|null,
     *     rules_origin?: string,
     *     method: string,
     *     uri: string,
     *     baseline: array<string, mixed>,
     *     scenarios: list<array<string, mixed>>,
     *     authenticate?: bool,
     *     user_model?: string|null,
     *     test_case?: string|null,
     *     refresh_database?: bool,
     *     target?: string,
     *     bindings?: array<string, mixed>|null,
     *     error_mode?: string,
     *     error_status?: int|null,
     * } $spec
     */
    public function build(array $spec): string
    {
        $method = strtoupper($spec['method']);
        $uri = $spec['uri'];
        $bindings = empty($spec['bindings']) ? null : RouteBindings::fromArray($spec['bindings']);
        $this->mode = (string) ($spec['error_mode'] ?? 'laravel');
        $this->status = (int) ($spec['error_status'] ?? 422);
        $uriExpr = $bindings === null || RouteBindings::parameterNames($bindings->uri) === [] ? var_export($uri, true) : '$this->testpilotUri';
        $helper = 'testpilotValidPayload_' . substr(md5(($spec['target'] ?? '') . $method . $uri), 0, 10);

        $lines = ['<?php', '', 'declare(strict_types=1);', ''];

        if (! empty($spec['test_case'])) {
            $lines[] = 'uses(\\' . ltrim((string) $spec['test_case'], '\\') . '::class);';
        }
        if (! empty($spec['refresh_database'])) {
            $lines[] = 'uses(\\Illuminate\\Foundation\\Testing\\RefreshDatabase::class);';
        }
        if (count($lines) > 4) {
            $lines[] = '';
        }

        $lines[] = '/**';
        $origin = $spec['rules_origin'] ?? ($this->short((string) ($spec['request_class'] ?? '')) . '::rules()');
        $lines[] = " * A request body that satisfies every rule in {$origin}.";
        $lines[] = ' * Each test changes one field and checks only that field\'s error.';
        $lines[] = ' */';
        $lines[] = "function {$helper}(): array";
        $lines[] = '{';
        $lines[] = '    return ' . PhpLiteral::export($spec['baseline'], 1) . ';';
        $lines[] = '}';
        $lines[] = '';

        $setup = [];
        if (! empty($spec['authenticate'])) {
            $user = ltrim((string) ($spec['user_model'] ?? 'App\\Models\\User'), '\\');
            $setup[] = '    // The route requires authentication, so requests are sent as a freshly created user.';
            $setup[] = "    \$this->actingAs(\\{$user}::factory()->create());";
        }
        if ($uriExpr === '$this->testpilotUri') {
            $setup[] = '    // Records for the URL parameters: ' . implode('; ', $bindings->describe());
            array_push($setup, ...$bindings->setupLines());
        }
        if ($setup !== []) {
            array_push($lines, 'beforeEach(function () {', ...[...$setup, '});', '']);
        }

        foreach ($spec['scenarios'] as $scenario) {
            if (! ($scenario['selected'] ?? true) || ($scenario['meta']['todo'] ?? null) !== null) {
                continue;
            }
            array_push($lines, ...[...$this->test($scenario, $method, $uriExpr, $helper), '']);
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return list<string>
     */
    private function test(array $scenario, string $method, string $uri, string $helper): array
    {
        $meta = (array) $scenario['meta'];
        $title = '[' . $scenario['id'] . '] ' . $this->lowerFirst((string) $scenario['name']);
        $lines = ['it(' . var_export($title, true) . ', function () {', "    \$payload = {$helper}();"];

        $target = (string) ($meta['target_field'] ?? $meta['field'] ?? '');
        $errorField = (string) ($meta['field'] ?? $target);

        match ($meta['action']) {
            'remove' => $lines[] = '    \\Illuminate\\Support\\Arr::forget($payload, ' . var_export($target, true) . ');',
            'set' => $lines[] = '    data_set($payload, ' . var_export($target, true) . ', ' . PhpLiteral::export($meta['value'] ?? null, 1) . ');',
            default => null,
        };

        foreach ((array) ($meta['also_set'] ?? []) as $path => $value) {
            $lines[] = '    data_set($payload, ' . var_export((string) $path, true) . ', ' . PhpLiteral::export($value, 1) . ');';
        }

        $lines[] = '';
        $call = $this->request($method, $uri);

        $field = var_export($errorField, true);
        $s = $this->status;
        $notRejected = static fn (string $why): array => [
            "    \$response = {$call};",
            '',
            "    // {$why}",
            "    expect(\$response->status())->not->toBe({$s}, (string) \$response->getContent());",
        ];

        array_push($lines, ...match ([$this->mode, $meta['expect']]) {
            ['status', 'error'] => ["    {$call}", "        ->assertStatus({$s});"],
            ['status', 'no_error'] => ["    {$call}", '        ->assertSuccessful()', "        ->assertJsonMissingValidationErrors([{$field}]);"],
            ['status', 'no_errors'] => ["    {$call}", '        ->assertSuccessful();'],
            ['redirect_errors', 'error'] => ["    {$call}", '        ->assertStatus(302)', "        ->assertSessionHasErrors([{$field}]);"],
            ['redirect_errors', 'no_error'] => ["    {$call}", '        ->assertRedirect()', "        ->assertSessionDoesntHaveErrors([{$field}]);"],
            ['redirect_errors', 'no_errors'] => ["    {$call}", '        ->assertRedirect()', '        ->assertSessionHasNoErrors();'],
            [$this->mode, 'error'] => ["    {$call}", '        ->assertStatus(422)', "        ->assertJsonValidationErrors([{$field}]);"],
            [$this->mode, 'no_error'] => ["    {$call}", '        ->assertSuccessful()', "        ->assertJsonMissingValidationErrors([{$field}]);"],
            [$this->mode, 'no_errors'] => ["    {$call}", '        ->assertSuccessful();'],
            default => $notRejected('Passing validation is what matters here; any status except 422 is accepted.'),
        });

        $lines[] = '});';

        return $lines;
    }

    private function request(string $method, string $literal): string
    {
        return match ($method) {
            'GET' => "\$this->getJson({$literal} . '?' . http_build_query(\$payload))",
            'DELETE' => "\$this->deleteJson({$literal}, \$payload)",
            'PUT' => "\$this->putJson({$literal}, \$payload)",
            'PATCH' => "\$this->patchJson({$literal}, \$payload)",
            default => "\$this->postJson({$literal}, \$payload)",
        };
    }

    private function lowerFirst(string $text): string
    {
        return $text === '' ? $text : strtolower($text[0]) . substr($text, 1);
    }

    private function short(string $class): string
    {
        return str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class;
    }
}
