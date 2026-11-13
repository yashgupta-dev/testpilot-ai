<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use TestPilot\Laravel\Services\AuditLogger;
use TestPilot\Laravel\Services\SettingsService;
use TestPilot\Laravel\TestPilotDashboard;

/** Passcode lock screen: status, unlock (throttled: 5 tries per minute per IP and user), lock. */
final class SessionController extends ApiController
{
    public function __construct(private readonly SettingsService $settings, private readonly AuditLogger $audit)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'locked' => ! $this->settings->isUnlocked($request),
            'passcode_enabled' => $this->settings->passcodeEnabled(),
            'can_manage_settings' => TestPilotDashboard::canManageSettings($request),
            'user' => AuditLogger::currentActor(),
        ]);
    }

    public function unlock(Request $request): JsonResponse
    {
        $data = $request->validate(['passcode' => ['required', 'string', 'max:64']]);
        $key = 'testpilot-unlock:' . sha1($request->ip() . '|' . AuditLogger::currentActor());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($key) . ' seconds.'], 429);
        }

        if (! $this->settings->verifyPasscode($data['passcode'])) {
            RateLimiter::hit($key, 60);
            $this->audit->log('dashboard.unlock_failed', null, ['ip' => $request->ip()]);

            return response()->json(['message' => 'That passcode is not correct.'], 422);
        }

        RateLimiter::clear($key);
        $this->settings->unlock($request);
        $this->audit->log('dashboard.unlocked', null, ['ip' => $request->ip()]);

        return response()->json(['locked' => false]);
    }

    public function lock(Request $request): JsonResponse
    {
        $this->settings->lock($request);

        return response()->json(['locked' => $this->settings->passcodeEnabled()]);
    }
}
