<?php

declare(strict_types=1);

namespace TestPilot\RateLimit;

/**
 * Turns an expected limit (N requests per D seconds) into concrete, deterministic cases.
 * No AI: every expectation comes from the throttle middleware or from #[RateLimit].
 */
final class RateLimitCaseGenerator
{
    /**
     * @param array{
     *     max_attempts: int,
     *     decay_seconds: int,
     *     basis_source: string,
     *     policy_max?: int,
     *     lockout?: bool,
     * } $spec
     *
     * @return list<array<string, mixed>>
     */
    public function generate(array $spec): array
    {
        $n = $spec['max_attempts'];
        $d = $spec['decay_seconds'];
        $window = self::window($d);
        $source = $spec['basis_source'];
        $tooMany = $n > ($spec['policy_max'] ?? 500);
        $todoTooMany = $tooMany ? "The limit ({$n}) is above the policy maximum of " . ($spec['policy_max'] ?? 500) . ' requests per test. Raise limits.max_rate_limit_attempts or test this by hand.' : null;

        $cases = [
            ['allow', "Allows {$n} request" . ($n === 1 ? '' : 's') . " within {$window}", "{$n} requests", 'none of them get 429', 'positive', $todoTooMany],
            ['block', 'Blocks request ' . ($n + 1) . ' with 429 Too Many Requests', ($n + 1) . ' requests', 'the last one gets 429', 'negative', $todoTooMany],
            ['retry_after', 'Tells the client when to retry', ($n + 1) . ' requests', "429 with Retry-After between 1 and {$d} seconds", 'negative', $todoTooMany],
            ['headers', 'Reports the limit in X-RateLimit headers', '1 request', "X-RateLimit-Limit: {$n}, X-RateLimit-Remaining: " . ($n - 1), 'positive', null],
            ['reset', "Allows requests again after {$window}", ($n + 1) . " requests, then wait {$d}s", 'the next request is not 429', 'edge', $todoTooMany],
            ['isolation', 'Limits each client separately', "{$n} requests from client A, 1 from client B", 'client B is not blocked', 'edge', $todoTooMany],
        ];

        if ($spec['lockout'] ?? false) {
            $cases[] = ['lockout', 'Locks the account after repeated failures', '—', 'account locked', 'negative', 'Account lockout is application logic (not the throttle middleware). Write this case by hand, or plan it as a unit test.'];
        }

        $scenarios = [];
        foreach ($cases as $i => [$kind, $name, $inputs, $expected, $type, $todo]) {
            $scenarios[] = [
                'id' => 'S' . ($i + 1),
                'name' => $name,
                'type' => $type,
                'module' => 'ratelimit',
                'description' => $todo ?? '',
                'inputs' => $inputs,
                'expected' => $expected,
                'basis' => 'confirmed',
                'basis_source' => $source,
                'confidence' => $todo === null ? 1.0 : 0.0,
                'priority' => in_array($kind, ['allow', 'block'], true) ? 'high' : 'medium',
                'selected' => $todo === null,
                'meta' => ['kind' => $kind, 'max_attempts' => $n, 'decay_seconds' => $d, 'todo' => $todo],
            ];
        }

        return $scenarios;
    }

    public static function window(int $seconds): string
    {
        if ($seconds % 3600 === 0) {
            $h = intdiv($seconds, 3600);

            return $h === 1 ? '1 hour' : "{$h} hours";
        }
        if ($seconds % 60 === 0) {
            $m = intdiv($seconds, 60);

            return $m === 1 ? '1 minute' : "{$m} minutes";
        }

        return "{$seconds} seconds";
    }
}
