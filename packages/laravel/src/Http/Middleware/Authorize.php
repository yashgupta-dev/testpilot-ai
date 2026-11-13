<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TestPilot\Laravel\TestPilotDashboard;

final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        $blocked = (array) config('testpilot.limits.blocked_environments', ['production']);

        if (in_array(app()->environment(), $blocked, true) && ! config('testpilot.dashboard.allow_in_production', false)) {
            abort(404);
        }

        if (! TestPilotDashboard::check($request)) {
            abort(403, 'You are not allowed to access TestPilot.');
        }

        app(\TestPilot\Laravel\Services\SettingsService::class)->apply();

        return $next($request);
    }
}
