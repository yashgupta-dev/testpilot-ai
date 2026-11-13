// Generated from the real rate-limit engine (RateLimitCaseGenerator + RateLimitTestBuilder).

export const rateLimitScenarios = [
    {
        "id": "S1",
        "name": "Allows 5 requests within 1 minute",
        "type": "positive",
        "module": "ratelimit",
        "description": "",
        "inputs": "5 requests",
        "expected": "none of them get 429",
        "basis": "confirmed",
        "basis_source": "#[RateLimit(maxAttempts: 5, decaySeconds: 60)] on AuthController::login",
        "confidence": 1,
        "priority": "high",
        "selected": true,
        "meta": {
            "kind": "allow",
            "max_attempts": 5,
            "decay_seconds": 60,
            "todo": null
        }
    },
    {
        "id": "S2",
        "name": "Blocks request 6 with 429 Too Many Requests",
        "type": "negative",
        "module": "ratelimit",
        "description": "",
        "inputs": "6 requests",
        "expected": "the last one gets 429",
        "basis": "confirmed",
        "basis_source": "#[RateLimit(maxAttempts: 5, decaySeconds: 60)] on AuthController::login",
        "confidence": 1,
        "priority": "high",
        "selected": true,
        "meta": {
            "kind": "block",
            "max_attempts": 5,
            "decay_seconds": 60,
            "todo": null
        }
    },
    {
        "id": "S3",
        "name": "Tells the client when to retry",
        "type": "negative",
        "module": "ratelimit",
        "description": "",
        "inputs": "6 requests",
        "expected": "429 with Retry-After between 1 and 60 seconds",
        "basis": "confirmed",
        "basis_source": "#[RateLimit(maxAttempts: 5, decaySeconds: 60)] on AuthController::login",
        "confidence": 1,
        "priority": "medium",
        "selected": true,
        "meta": {
            "kind": "retry_after",
            "max_attempts": 5,
            "decay_seconds": 60,
            "todo": null
        }
    },
    {
        "id": "S4",
        "name": "Reports the limit in X-RateLimit headers",
        "type": "positive",
        "module": "ratelimit",
        "description": "",
        "inputs": "1 request",
        "expected": "X-RateLimit-Limit: 5, X-RateLimit-Remaining: 4",
        "basis": "confirmed",
        "basis_source": "#[RateLimit(maxAttempts: 5, decaySeconds: 60)] on AuthController::login",
        "confidence": 1,
        "priority": "medium",
        "selected": true,
        "meta": {
            "kind": "headers",
            "max_attempts": 5,
            "decay_seconds": 60,
            "todo": null
        }
    },
    {
        "id": "S5",
        "name": "Allows requests again after 1 minute",
        "type": "edge",
        "module": "ratelimit",
        "description": "",
        "inputs": "6 requests, then wait 60s",
        "expected": "the next request is not 429",
        "basis": "confirmed",
        "basis_source": "#[RateLimit(maxAttempts: 5, decaySeconds: 60)] on AuthController::login",
        "confidence": 1,
        "priority": "medium",
        "selected": true,
        "meta": {
            "kind": "reset",
            "max_attempts": 5,
            "decay_seconds": 60,
            "todo": null
        }
    },
    {
        "id": "S6",
        "name": "Limits each client separately",
        "type": "edge",
        "module": "ratelimit",
        "description": "",
        "inputs": "5 requests from client A, 1 from client B",
        "expected": "client B is not blocked",
        "basis": "confirmed",
        "basis_source": "#[RateLimit(maxAttempts: 5, decaySeconds: 60)] on AuthController::login",
        "confidence": 1,
        "priority": "medium",
        "selected": true,
        "meta": {
            "kind": "isolation",
            "max_attempts": 5,
            "decay_seconds": 60,
            "todo": null
        }
    },
    {
        "id": "S7",
        "name": "Locks the account after repeated failures",
        "type": "negative",
        "module": "ratelimit",
        "description": "Account lockout is application logic (not the throttle middleware). Write this case by hand, or plan it as a unit test.",
        "inputs": "—",
        "expected": "account locked",
        "basis": "confirmed",
        "basis_source": "#[RateLimit(maxAttempts: 5, decaySeconds: 60)] on AuthController::login",
        "confidence": 0,
        "priority": "medium",
        "selected": false,
        "meta": {
            "kind": "lockout",
            "max_attempts": 5,
            "decay_seconds": 60,
            "todo": "Account lockout is application logic (not the throttle middleware). Write this case by hand, or plan it as a unit test."
        }
    }
]

export const rateLimitContext = {
    "method": "POST",
    "uri": "/api/login",
    "authenticate": false,
    "keyed_by": "ip",
    "max_attempts": 5,
    "decay_seconds": 60,
    "source": "#[RateLimit(maxAttempts: 5, decaySeconds: 60)] on AuthController::login",
    "route_throttle": {
        "source": "throttle:5,1",
        "max_attempts": 5,
        "decay_seconds": 60,
        "limiter": null,
        "prefix": null
    },
    "declared": {
        "max_attempts": 5,
        "decay_seconds": 60
    },
    "lockout": true,
    "warnings": []
}

export const rateLimitCode = "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Testing\\TestResponse;\n\nuses(\\Tests\\TestCase::class);\n\n/**\n * Expected limit: 5 requests per 60 seconds (#[RateLimit(maxAttempts: 5, decaySeconds: 60)] on AuthController::login).\n * The request body is intentionally empty: the throttle counts every request that reaches\n * the route, whatever the controller answers (200, 401, 422…).\n */\nfunction testpilotHit_5586c16b04($test, string $client = '10.0.0.1'): TestResponse\n{\n    return $test->withServerVariables(['REMOTE_ADDR' => $client])->postJson('/api/login');\n}\n\n/** Sends the full allowance and returns the responses. */\nfunction testpilotFlood_5586c16b04($test): array\n{\n    return array_map(fn (int $attempt) => testpilotHit_5586c16b04($test), range(1, 5));\n}\n\nit('[S1] allows 5 requests within 1 minute', function () {\n    foreach (testpilotFlood_5586c16b04($this) as $i => $response) {\n        expect($response->status())->not->toBe(429, 'Request ' . ($i + 1) . \" of 5 was rate limited too early\");\n    }\n});\n\nit('[S2] blocks request 6 with 429 Too Many Requests', function () {\n    testpilotFlood_5586c16b04($this);\n\n    $response = testpilotHit_5586c16b04($this);\n\n    expect($response->status())->toBe(429, \"Request 6 should be rate limited (limit 5), got {$response->status()}\");\n});\n\nit('[S3] tells the client when to retry', function () {\n    testpilotFlood_5586c16b04($this);\n\n    $response = testpilotHit_5586c16b04($this);\n\n    expect($response->status())->toBe(429, \"Request 6 should be rate limited (limit 5), got {$response->status()}\");\n    $response->assertHeader('Retry-After');\n    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);\n});\n\nit('[S4] reports the limit in X-RateLimit headers', function () {\n    $response = testpilotHit_5586c16b04($this);\n\n    expect($response->headers->get('X-RateLimit-Limit'))->toBe('5');\n    expect($response->headers->get('X-RateLimit-Remaining'))->toBe('4');\n});\n\nit('[S5] allows requests again after 1 minute', function () {\n    testpilotFlood_5586c16b04($this);\n    expect(testpilotHit_5586c16b04($this)->status())->toBe(429, 'The limit was never reached, so the reset cannot be checked');\n\n    $this->travel(60 + 1)->seconds();\n\n    expect(testpilotHit_5586c16b04($this)->status())->not->toBe(429, 'Still blocked 60 seconds after the limit was reached');\n});\n\nit('[S6] limits each client separately', function () {\n    testpilotFlood_5586c16b04($this);\n\n    $response = testpilotHit_5586c16b04($this, '10.0.0.2');\n\n    expect($response->status())->not->toBe(429, 'A different client was blocked by the first client\\'s requests');\n});\n"
