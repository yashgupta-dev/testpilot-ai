<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TestPilot\Laravel\Models\AiBatch;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\AiUsageService;
use TestPilot\Laravel\Services\BatchPlanService;
use TestPilot\Laravel\Services\PlanService;

final class AiController extends ApiController
{
    public function usage(Request $request, AiUsageService $usage): JsonResponse
    {
        return response()->json($usage->summary(max(1, min(90, (int) $request->query('days', 30)))));
    }

    /** Plan many targets in one discounted Message Batch. Body: {module, targets?: ids[]} — no ids means every unplanned target. */
    public function submitBatch(Request $request, BatchPlanService $batches): JsonResponse
    {
        $data = $request->validate(['module' => ['required', 'in:unit,feature'], 'targets' => ['array'], 'targets.*' => ['string']]);

        return $this->attempt(function () use ($data, $batches): array {
            $query = Target::query()->active()->where('enabled', true);
            $targets = empty($data['targets'])
                ? $query->whereIn('status', [Target::NOT_PLANNED, Target::STALE])->get()->filter(fn (Target $t): bool => in_array($data['module'], PlanService::plannableModules($t), true))
                : $query->whereKey($data['targets'])->get();
            $result = $batches->submit($targets, $data['module']);

            return ['batch' => ['id' => $result['batch']->id, 'status' => $result['batch']->status, 'requests' => $result['batch']->request_count], 'skipped' => $result['skipped']];
        }, 202);
    }

    public function pollBatch(AiBatch $batch, BatchPlanService $batches): JsonResponse
    {
        return $this->attempt(fn (): array => ['ended' => $batches->poll($batch), 'status' => $batch->refresh()->status, 'counts' => $batch->counts]);
    }
}
