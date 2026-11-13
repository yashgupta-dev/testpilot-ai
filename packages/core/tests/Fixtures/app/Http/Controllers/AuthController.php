<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use TestPilot\Attributes\RateLimit;
use TestPilot\Attributes\TestPilot;
use TestPilot\Attributes\Validation;

#[TestPilot(area: 'Auth')]
final class AuthController
{
    #[Validation]
    #[RateLimit(maxAttempts: 5, decaySeconds: 60, lockout: true)]
    public function login(object $request): array
    {
        return ['ok' => true];
    }
}
