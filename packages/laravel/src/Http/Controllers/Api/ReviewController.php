<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Services\ApprovalService;
use TestPilot\Laravel\Services\DashboardService;

final class ReviewController extends ApiController
{
    public function __construct(private readonly DashboardService $dashboard, private readonly ApprovalService $approvals)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->reviewQueue()]);
    }

    public function approve(Request $request, GeneratedTest $test): JsonResponse
    {
        $this->authorizeApproval($request);

        return $this->attempt(fn (): array => ['status' => $this->approvals->approve($test)->status]);
    }

    public function reject(Request $request, GeneratedTest $test): JsonResponse
    {
        $this->authorizeApproval($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->attempt(fn (): array => ['status' => $this->approvals->reject($test, $data['reason'])->status]);
    }
}
