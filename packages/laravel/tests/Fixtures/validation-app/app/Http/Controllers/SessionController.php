<?php

declare(strict_types=1);

namespace Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use TestPilot\Attributes\RateLimit;
use TestPilot\Attributes\TestPilot;

#[TestPilot(area: 'Auth')]
final class SessionController
{
    /** The declared limit is the expectation; the route's throttle middleware must match it. */
    #[RateLimit(maxAttempts: 3, decaySeconds: 60)]
    public function store(): JsonResponse
    {
        return new JsonResponse(['token' => 'demo'], 201);
    }

    /** No numbers declared: the limit is read from the route's throttle:2,1. */
    #[RateLimit]
    public function ping(): JsonResponse
    {
        return new JsonResponse(['pong' => true]);
    }

    /** Named limiter without a declared limit: TestPilot must ask for one. */
    #[RateLimit]
    public function status(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
