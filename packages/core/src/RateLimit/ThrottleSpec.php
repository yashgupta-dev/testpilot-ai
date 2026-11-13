<?php

declare(strict_types=1);

namespace TestPilot\RateLimit;

/**
 * A route's throttle middleware, parsed without running it.
 *
 *  - "throttle:60,1"            → 60 requests per 1 minute
 *  - "throttle:10,0.5,uploads"  → 10 requests per 30 seconds, key prefix "uploads"
 *  - "throttle:login"           → named limiter (RateLimiter::for('login', …)); its limit lives in code
 *  - "Illuminate\Routing\Middleware\ThrottleRequests:60,1" → same as "throttle:60,1"
 */
final class ThrottleSpec
{
    public function __construct(
        public readonly string $source,
        public readonly ?int $maxAttempts = null,
        public readonly ?int $decaySeconds = null,
        public readonly ?string $limiter = null,
        public readonly ?string $prefix = null,
    ) {
    }

    public static function parse(?string $middleware): ?self
    {
        if ($middleware === null || $middleware === '') {
            return null;
        }

        $isThrottle = str_starts_with($middleware, 'throttle')
            || str_contains($middleware, 'ThrottleRequests');
        if (! $isThrottle) {
            return null;
        }

        $colon = strpos($middleware, ':');
        if ($colon === false) {
            // Bare "throttle" middleware: Laravel's default is 60 requests per minute.
            return new self($middleware, 60, 60);
        }

        $params = array_map('trim', explode(',', substr($middleware, $colon + 1)));
        $first = $params[0] ?? '';

        if ($first !== '' && ! is_numeric($first)) {
            return new self($middleware, limiter: $first);
        }

        $max = $first === '' ? 60 : (int) $first;
        $minutes = isset($params[1]) && $params[1] !== '' && is_numeric($params[1]) ? (float) $params[1] : 1.0;
        $prefix = isset($params[2]) && $params[2] !== '' ? $params[2] : null;

        return new self($middleware, $max, max(1, (int) round($minutes * 60)), null, $prefix);
    }

    public function isNamed(): bool
    {
        return $this->limiter !== null;
    }

    public function describe(): string
    {
        if ($this->isNamed()) {
            return "named limiter '{$this->limiter}'";
        }

        return "{$this->maxAttempts} per " . RateLimitCaseGenerator::window((int) $this->decaySeconds);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'max_attempts' => $this->maxAttempts,
            'decay_seconds' => $this->decaySeconds,
            'limiter' => $this->limiter,
            'prefix' => $this->prefix,
        ];
    }
}
