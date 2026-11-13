<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TestPilot\Laravel\Jobs\RunTestsJob;
use TestPilot\Laravel\Models\Run;
use TestPilot\Laravel\Services\DashboardService;
use TestPilot\Laravel\Services\RunService;

final class RunController extends ApiController
{
    public function __construct(private readonly DashboardService $dashboard, private readonly RunService $runs)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->dashboard->runs());
    }

    public function show(Run $run): JsonResponse
    {
        return response()->json($this->dashboard->run($run));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['targets' => ['nullable', 'array'], 'targets.*' => ['string', 'size:26']]);

        return $this->attempt(function () use ($data): array {
            $run = $this->runs->queue('dashboard', $data['targets'] ?? null);
            if ($run->wasRecentlyCreated) {
                RunTestsJob::dispatch($run);
            }

            return $this->dashboard->run($run->refresh());
        }, 202);
    }

    public function cancel(Run $run): JsonResponse
    {
        return $this->attempt(fn (): array => $this->dashboard->run($this->runs->cancel($run)));
    }
}
