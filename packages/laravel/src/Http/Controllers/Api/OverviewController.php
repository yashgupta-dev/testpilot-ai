<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use TestPilot\Laravel\Services\DashboardService;
use TestPilot\Laravel\Services\ScanService;

final class OverviewController extends ApiController
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->dashboard->overview());
    }

    public function environment(): JsonResponse
    {
        return response()->json($this->dashboard->environment());
    }

    public function scan(ScanService $scanner): JsonResponse
    {
        return $this->attempt(fn (): array => $scanner->scan());
    }
}
