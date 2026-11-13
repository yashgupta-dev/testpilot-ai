<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Http\Controllers\Api;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\TestPilotDashboard;
use Throwable;

abstract class ApiController extends Controller
{
    /** Runs an action and turns TestPilot errors into a 422 the UI can show verbatim. */
    protected function attempt(Closure $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json($action(), $status);
        } catch (TestPilotException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }

    protected function authorizeApproval(Request $request): void
    {
        abort_unless(TestPilotDashboard::canApprove($request), 403, 'You are not allowed to approve or reject tests.');
    }
}
