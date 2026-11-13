<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TestPilot\Laravel\Services\SettingsService;

/** When a dashboard passcode is set, every API call needs an unlocked session (HTTP 423 otherwise). */
final class RequirePasscode
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->isUnlocked($request)) {
            return response()->json(['message' => 'The TestPilot dashboard is locked. Enter the passcode.', 'locked' => true], 423);
        }

        return $next($request);
    }
}
