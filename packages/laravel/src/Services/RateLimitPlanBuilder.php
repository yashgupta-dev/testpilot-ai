<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Models\Target;
use TestPilot\RateLimit\RateLimitCaseGenerator;
use TestPilot\RateLimit\ThrottleSpec;

/**
 * Builds a rate-limit test plan from the route's throttle middleware and #[RateLimit].
 * Nothing is executed: named limiters (RateLimiter::for) are closures, so their limit must be
 * declared with #[RateLimit(maxAttempts: …, decaySeconds: …)]. When both exist the declared
 * limit is the expectation, so a weaker middleware shows up as a suspected bug.
 */
final class RateLimitPlanBuilder
{
    public function __construct(
        private readonly TestPilotManager $manager,
        private readonly RouteBindingResolver $bindings,
        private readonly RateLimitCaseGenerator $cases = new RateLimitCaseGenerator(),
    ) {
    }

    /** @return array{scenarios: list<array<string, mixed>>, context: array<string, mixed>, notes: string} */
    public function build(Target $target): array
    {
        $route = $target->route;
        if (! is_array($route)) {
            throw new TestPilotException("No route calls {$target->shortName()}. Rate-limit tests are generated for controller methods that handle an HTTP route." . str_replace('#[Validation]', '#[RateLimit]', ValidationPlanBuilder::routedAlternatives($target)));
        }
        $bindings = $this->bindings->resolve($target, $route);

        $throttle = ThrottleSpec::parse($route['throttle'] ?? null);
        $options = (array) ($target->config['options']['ratelimit'] ?? []);
        $declaredMax = isset($options['max_attempts']) ? (int) $options['max_attempts'] : null;
        $declaredDecay = isset($options['decay_seconds']) ? (int) $options['decay_seconds'] : null;
        $short = $target->shortName();
        $warnings = [];

        if ($declaredMax !== null) {
            $max = $declaredMax;
            $decay = $declaredDecay ?? $throttle?->decaySeconds ?? 60;
            $source = "#[RateLimit(maxAttempts: {$max}" . ($declaredDecay !== null ? ", decaySeconds: {$declaredDecay}" : '') . ")] on {$short}";
            if ($throttle === null) {
                $warnings[] = "The route has no throttle middleware, but #[RateLimit] declares {$max} per " . RateLimitCaseGenerator::window($decay) . '. Expect the blocking cases to fail until the route is throttled.';
            } elseif (! $throttle->isNamed() && ($throttle->maxAttempts !== $max || $throttle->decaySeconds !== $decay)) {
                $warnings[] = "The route uses {$throttle->source} ({$throttle->describe()}) but #[RateLimit] declares {$max} per " . RateLimitCaseGenerator::window($decay) . '. The declared limit is tested.';
            }
        } elseif ($throttle !== null && ! $throttle->isNamed()) {
            $max = (int) $throttle->maxAttempts;
            $decay = (int) $throttle->decaySeconds;
            $source = "route middleware {$throttle->source}";
        } elseif ($throttle !== null) {
            throw new TestPilotException("{$route['uri']} uses the named limiter '{$throttle->limiter}'. Its limit is defined in code (RateLimiter::for), which TestPilot does not execute. Declare the expected limit: #[RateLimit(maxAttempts: 5, decaySeconds: 60)].");
        } else {
            throw new TestPilotException("{$route['uri']} has no throttle middleware and #[RateLimit] declares no limit. Add ->middleware('throttle:5,1') to the route or #[RateLimit(maxAttempts: 5, decaySeconds: 60)].");
        }

        $middleware = (array) ($route['middleware'] ?? []);
        $authenticate = collect($middleware)->contains(fn (string $m): bool => $m === 'auth' || str_starts_with($m, 'auth:') || str_contains($m, 'Authenticate'));
        $lockout = (bool) ($options['lockout'] ?? false);

        $scenarios = $this->cases->generate([
            'max_attempts' => max(1, $max),
            'decay_seconds' => max(1, $decay),
            'basis_source' => $source,
            'policy_max' => (int) $this->manager->core()->limits->maxRateLimitAttempts,
            'lockout' => $lockout,
        ]);

        $method = collect((array) $route['methods'])->first(fn (string $m): bool => $m !== 'HEAD') ?? 'GET';
        $selected = count(array_filter($scenarios, static fn (array $s): bool => $s['selected']));

        return [
            'scenarios' => $scenarios,
            'notes' => "{$selected} cases for {$max} requests per " . RateLimitCaseGenerator::window($decay) . " (from {$source}). Requests go through the real middleware; time is moved with travel(), never slept.",
            'context' => [
                'method' => $method,
                'uri' => $route['uri'],
                'bindings' => $bindings->isEmpty() ? null : $bindings->toArray(),
                'authenticate' => $authenticate,
                'keyed_by' => $authenticate ? 'user' : 'ip',
                'max_attempts' => $max,
                'decay_seconds' => $decay,
                'source' => $source,
                'route_throttle' => $throttle?->toArray(),
                'declared' => $declaredMax === null ? null : ['max_attempts' => $declaredMax, 'decay_seconds' => $declaredDecay],
                'lockout' => $lockout,
                'warnings' => $warnings,
            ],
        ];
    }
}
