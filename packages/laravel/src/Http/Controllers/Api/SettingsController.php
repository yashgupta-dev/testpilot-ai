<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TestPilot\Ai\AiRequest;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Services\AuditLogger;
use TestPilot\Laravel\Services\SettingsService;
use TestPilot\Laravel\Services\TestPilotManager;
use TestPilot\Laravel\TestPilotDashboard;

final class SettingsController extends ApiController
{
    public function __construct(private readonly SettingsService $settings, private readonly AuditLogger $audit)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'fields' => $this->settings->export(),
            'passcode' => [...$this->settings->passcodeStatus(), 'locked_by_env' => $this->settings->passcodeLocked()],
            'editable' => $this->settings->editable(),
            'can_manage' => TestPilotDashboard::canManageSettings($request),
            'access' => [
                'approver' => TestPilotDashboard::canApprove($request),
                'environment' => app()->environment(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $values = (array) $request->input('values', []);

        return $this->attempt(function () use ($values): array {
            $changed = $this->settings->update($values, AuditLogger::currentActor());
            if ($changed !== []) {
                // Values are not logged: some are secrets.
                $this->audit->log('settings.updated', null, ['keys' => $changed]);
            }

            return ['changed' => $changed, 'fields' => $this->settings->export()];
        });
    }

    public function passcode(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['current' => ['nullable', 'string', 'max:64'], 'passcode' => ['nullable', 'string', 'max:64'], 'remove' => ['boolean']]);

        return $this->attempt(function () use ($data, $request): array {
            $remove = (bool) ($data['remove'] ?? false);
            if (! $remove && ($data['passcode'] ?? null) === null) {
                throw new TestPilotException('Enter a new passcode, or choose to remove it.');
            }
            $this->settings->setPasscode($data['current'] ?? null, $remove ? null : $data['passcode'], AuditLogger::currentActor());
            $this->audit->log($remove ? 'settings.passcode_removed' : 'settings.passcode_changed', null, []);
            if (! $remove) {
                $this->settings->unlock($request); // keep the person who just set it signed in
            }

            return ['passcode' => [...$this->settings->passcodeStatus(), 'locked_by_env' => $this->settings->passcodeLocked()]];
        });
    }

    /** Sends a tiny request with the saved settings so admins know the key and model work. */
    public function testAi(Request $request, TestPilotManager $manager): JsonResponse
    {
        $this->authorizeManage($request);

        return $this->attempt(function () use ($manager): array {
            $started = hrtime(true);
            $response = $manager->fresh()->core()->ai()->send(AiRequest::single('test', 'You are a connectivity check.', 'Reply with exactly: OK', 512)); // room for reasoning models, which think before answering

            return ['ok' => true, 'model' => $response->model, 'reply' => trim($response->content), 'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000)];
        });
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(TestPilotDashboard::canManageSettings($request), 403, 'You are not allowed to change TestPilot settings.');
    }
}
