<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use TestPilot\RateLimit\RateLimitCaseGenerator;
use TestPilot\RateLimit\RateLimitTestBuilder;
use TestPilot\RateLimit\ThrottleSpec;

describe('ThrottleSpec', function () {
    it('parses throttle middleware strings without running them', function (string $middleware, ?int $max, ?int $decay, ?string $limiter) {
        $spec = ThrottleSpec::parse($middleware);

        expect($spec?->maxAttempts)->toBe($max)
            ->and($spec?->decaySeconds)->toBe($decay)
            ->and($spec?->limiter)->toBe($limiter);
    })->with([
        'per minute' => ['throttle:60,1', 60, 60, null],
        'fractional minutes' => ['throttle:10,0.5,uploads', 10, 30, null],
        'per hour' => ['throttle:100,60', 100, 3600, null],
        'class name' => ['Illuminate\Routing\Middleware\ThrottleRequests:5,1', 5, 60, null],
        'bare default' => ['throttle', 60, 60, null],
        'named limiter' => ['throttle:login', null, null, 'login'],
        'named via class' => ['Illuminate\Routing\Middleware\ThrottleRequests:api', null, null, 'api'],
    ]);

    it('ignores middleware that is not a throttle', function () {
        expect(ThrottleSpec::parse('auth:sanctum'))->toBeNull()
            ->and(ThrottleSpec::parse(null))->toBeNull()
            ->and(ThrottleSpec::parse('throttle:5,1')->describe())->toBe('5 per 1 minute')
            ->and(ThrottleSpec::parse('throttle:login')->describe())->toBe("named limiter 'login'");
    });
});

describe('RateLimitCaseGenerator', function () {
    it('derives six confirmed cases from the limit', function () {
        $cases = (new RateLimitCaseGenerator())->generate(['max_attempts' => 5, 'decay_seconds' => 60, 'basis_source' => 'route middleware throttle:5,1']);

        expect(array_column(array_column($cases, 'meta'), 'kind'))->toBe(['allow', 'block', 'retry_after', 'headers', 'reset', 'isolation'])
            ->and(array_column($cases, 'id'))->toBe(['S1', 'S2', 'S3', 'S4', 'S5', 'S6'])
            ->and($cases[0]['name'])->toBe('Allows 5 requests within 1 minute')
            ->and($cases[1]['name'])->toBe('Blocks request 6 with 429 Too Many Requests')
            ->and($cases[3]['expected'])->toBe('X-RateLimit-Limit: 5, X-RateLimit-Remaining: 4')
            ->and(array_unique(array_column($cases, 'basis')))->toBe(['confirmed'])
            ->and(array_unique(array_column($cases, 'module')))->toBe(['ratelimit'])
            ->and(array_unique(array_column($cases, 'selected')))->toBe([true]);
    });

    it('leaves limits above the policy maximum and account lockout to a human', function () {
        $cases = (new RateLimitCaseGenerator())->generate(['max_attempts' => 1000, 'decay_seconds' => 3600, 'basis_source' => 'x', 'policy_max' => 500, 'lockout' => true]);
        $byKind = array_column($cases, null, 'id');

        expect($byKind['S1']['selected'])->toBeFalse()
            ->and($byKind['S1']['meta']['todo'])->toContain('policy maximum of 500')
            ->and($byKind['S4']['selected'])->toBeTrue() // one request is always safe
            ->and($byKind['S7']['meta']['kind'])->toBe('lockout')
            ->and($byKind['S7']['meta']['todo'])->toContain('application logic');
    });

    it('describes windows in human units', function () {
        expect(RateLimitCaseGenerator::window(60))->toBe('1 minute')
            ->and(RateLimitCaseGenerator::window(30))->toBe('30 seconds')
            ->and(RateLimitCaseGenerator::window(7200))->toBe('2 hours');
    });
});

describe('RateLimitTestBuilder', function () {
    $build = function (array $overrides = []): string {
        $cases = (new RateLimitCaseGenerator())->generate(['max_attempts' => 3, 'decay_seconds' => 60, 'basis_source' => 'x', 'lockout' => true]);

        return (new RateLimitTestBuilder())->build([
            'method' => 'POST', 'uri' => '/api/login', 'scenarios' => $cases, 'max_attempts' => 3, 'decay_seconds' => 60,
            'test_case' => 'Tests\TestCase', 'target' => 'App\Http\Controllers\AuthController::login', ...$overrides,
        ]);
    };

    it('builds valid PHP that moves the clock instead of sleeping', function () use ($build) {
        $code = $build();

        expect((new ParserFactory())->createForNewestSupportedVersion()->parse($code))->not->toBeNull()
            ->and($code)->toContain('uses(\Tests\TestCase::class);')
            ->and($code)->toContain("->postJson('/api/login')")
            ->and($code)->toContain('range(1, 3)')
            ->and($code)->toContain('$this->travel(60 + 1)->seconds();')
            ->and($code)->toContain("toBe('2')") // X-RateLimit-Remaining after the first request
            ->and($code)->not->toContain('sleep(')
            ->and($code)->not->toContain('[S7]') // lockout is a human case
            ->and(substr_count($code, "it('[S"))->toBe(6);
    });

    it('signs in a user and switches user for the isolation case when the route needs auth', function () use ($build) {
        $code = $build(['authenticate' => true, 'user_model' => 'App\Models\Admin', 'method' => 'GET']);

        expect($code)->toContain('$this->actingAs(\App\Models\Admin::factory()->create());')
            ->and(substr_count($code, 'App\Models\Admin::factory()'))->toBe(2)
            ->and($code)->toContain("->getJson('/api/login')")
            ->and($code)->toContain("'10.0.0.2'");
    });
});
