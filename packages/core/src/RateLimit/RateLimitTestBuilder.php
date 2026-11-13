<?php

declare(strict_types=1);

namespace TestPilot\RateLimit;

use TestPilot\Http\RouteBindings;

/**
 * Writes the Pest file for approved rate-limit cases. Requests go through the real route and
 * middleware stack; the clock is moved with travel(), so tests never sleep.
 */
final class RateLimitTestBuilder
{
    private const CLIENT_A = '10.0.0.1';
    private const CLIENT_B = '10.0.0.2';

    /**
     * @param array{
     *     method: string,
     *     uri: string,
     *     scenarios: list<array<string, mixed>>,
     *     max_attempts: int,
     *     decay_seconds: int,
     *     authenticate?: bool,
     *     user_model?: string|null,
     *     test_case?: string|null,
     *     refresh_database?: bool,
     *     target?: string,
     *     source?: string,
     *     bindings?: array<string, mixed>|null,
     * } $spec
     */
    public function build(array $spec): string
    {
        $method = strtoupper($spec['method']);
        $uri = $spec['uri'];
        $n = (int) $spec['max_attempts'];
        $d = (int) $spec['decay_seconds'];
        $auth = ! empty($spec['authenticate']);
        $user = '\\' . ltrim((string) ($spec['user_model'] ?? 'App\\Models\\User'), '\\');
        $hash = substr(md5(($spec['target'] ?? '') . $method . $uri), 0, 10);
        $hit = "testpilotHit_{$hash}";
        $flood = "testpilotFlood_{$hash}";
        $bindings = empty($spec['bindings']) ? null : RouteBindings::fromArray($spec['bindings']);
        $dynamic = $bindings !== null && RouteBindings::parameterNames($bindings->uri) !== [];

        $lines = ['<?php', '', 'declare(strict_types=1);', '', 'use Illuminate\\Testing\\TestResponse;', ''];
        if (! empty($spec['test_case'])) {
            $lines[] = 'uses(\\' . ltrim((string) $spec['test_case'], '\\') . '::class);';
        }
        if (! empty($spec['refresh_database'])) {
            $lines[] = 'uses(\\Illuminate\\Foundation\\Testing\\RefreshDatabase::class);';
        }

        array_push(
            $lines,
            '',
            '/**',
            " * Expected limit: {$n} requests per {$d} seconds" . (isset($spec['source']) ? " ({$spec['source']})." : '.'),
            ' * The request body is intentionally empty: the throttle counts every request that reaches',
            ' * the route, whatever the controller answers (200, 401, 422…).',
            ' */',
            "function {$hit}(\$test, string \$client = " . var_export(self::CLIENT_A, true) . '): TestResponse',
            '{',
            "    return \$test->withServerVariables(['REMOTE_ADDR' => \$client])->" . $this->call($method, $dynamic ? '$test->testpilotUri' : var_export($uri, true)) . ';',
            '}',
            '',
            '/** Sends the full allowance and returns the responses. */',
            "function {$flood}(\$test): array",
            '{',
            "    return array_map(fn (int \$attempt) => {$hit}(\$test), range(1, {$n}));",
            '}',
            '',
        );

        $setup = [];
        if ($auth) {
            $setup[] = '    // The route requires authentication; the limiter then counts per user.';
            $setup[] = "    \$this->actingAs({$user}::factory()->create());";
        }
        if ($dynamic) {
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
            $title = '[' . $scenario['id'] . '] ' . $this->lowerFirst((string) $scenario['name']);
            $body = $this->body((string) $scenario['meta']['kind'], $n, $d, $hit, $flood, $auth, $user);
            array_push($lines, 'it(' . var_export($title, true) . ', function () {', ...[...$body, '});', '']);
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /** @return list<string> */
    private function body(string $kind, int $n, int $d, string $hit, string $flood, bool $auth, string $user): array
    {
        $blocked = 'expect($response->status())->toBe(429, "Request ' . ($n + 1) . " should be rate limited (limit {$n}), got {\$response->status()}\");";

        return match ($kind) {
            'allow' => [
                "    foreach ({$flood}(\$this) as \$i => \$response) {",
                '        expect($response->status())->not->toBe(429, \'Request \' . ($i + 1) . " of ' . $n . ' was rate limited too early");',
                '    }',
            ],
            'block' => [
                "    {$flood}(\$this);",
                '',
                "    \$response = {$hit}(\$this);",
                '',
                "    {$blocked}",
            ],
            'retry_after' => [
                "    {$flood}(\$this);",
                '',
                "    \$response = {$hit}(\$this);",
                '',
                "    {$blocked}",
                "    \$response->assertHeader('Retry-After');",
                "    expect((int) \$response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual({$d});",
            ],
            'headers' => [
                "    \$response = {$hit}(\$this);",
                '',
                "    expect(\$response->headers->get('X-RateLimit-Limit'))->toBe('{$n}');",
                "    expect(\$response->headers->get('X-RateLimit-Remaining'))->toBe('" . ($n - 1) . "');",
            ],
            'reset' => [
                "    {$flood}(\$this);",
                "    expect({$hit}(\$this)->status())->toBe(429, 'The limit was never reached, so the reset cannot be checked');",
                '',
                "    \$this->travel({$d} + 1)->seconds();",
                '',
                "    expect({$hit}(\$this)->status())->not->toBe(429, 'Still blocked {$d} seconds after the limit was reached');",
            ],
            'isolation' => [
                "    {$flood}(\$this);",
                '',
                ...($auth ? ["    \$this->actingAs({$user}::factory()->create());"] : []),
                "    \$response = {$hit}(\$this, " . var_export(self::CLIENT_B, true) . ');',
                '',
                "    expect(\$response->status())->not->toBe(429, 'A different client was blocked by the first client\\'s requests');",
            ],
            default => ["    \$this->markTestSkipped('Unknown case kind {$kind}');"],
        };
    }

    private function call(string $method, string $literal): string
    {
        return match ($method) {
            'GET' => "getJson({$literal})",
            'DELETE' => "deleteJson({$literal})",
            'PUT' => "putJson({$literal})",
            'PATCH' => "patchJson({$literal})",
            default => "postJson({$literal})",
        };
    }

    private function lowerFirst(string $text): string
    {
        return $text === '' ? $text : strtolower($text[0]) . substr($text, 1);
    }
}
