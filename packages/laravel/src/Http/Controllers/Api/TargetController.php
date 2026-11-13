<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TestPilot\Laravel\Jobs\GenerateTestsJob;
use TestPilot\Laravel\Jobs\ProposePlanJob;
use TestPilot\Laravel\Models\Plan;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\DashboardService;
use TestPilot\Laravel\Services\GenerationService;
use TestPilot\Laravel\Services\PlanService;

final class TargetController extends ApiController
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->targets($request->only(['status', 'search', 'area'])));
    }

    public function show(Request $request, Target $target): JsonResponse
    {
        return response()->json($this->dashboard->target($target, $request->query('module')));
    }

    /** Propose a plan: AI scenarios for "unit"; code-derived cases for "validation" and "ratelimit" (queued). */
    public function plan(Request $request, Target $target): JsonResponse
    {
        $validated = $request->validate(['module' => ['nullable', 'string', 'in:' . implode(',', PlanService::MODULES)]]);
        $module = (string) ($validated['module'] ?? '') ?: 'unit';

        return $this->attempt(function () use ($target, $module): array {
            if (! $target->enabled) {
                throw new \TestPilot\Exceptions\TestPilotException("{$target->key} is skipped (#[SkipTest]).");
            }
            if (! in_array($module, PlanService::plannableModules($target), true)) {
                throw new \TestPilot\Exceptions\TestPilotException("{$target->shortName()} has no {$module} module. Add the matching attribute first.");
            }
            // HTTP modules need a route: answer now instead of queuing a job that can only fail.
            if (in_array($module, PlanService::ROUTED_MODULES, true) && ! is_array($target->route)) {
                $attribute = ['validation' => '#[Validation]', 'ratelimit' => '#[RateLimit]', 'feature' => '#[FeatureTest]'][$module];
                throw new \TestPilot\Exceptions\TestPilotException(
                    "No route calls {$target->shortName()}, so there is no endpoint to test." . str_replace('#[Validation]', $attribute, \TestPilot\Laravel\Services\ValidationPlanBuilder::routedAlternatives($target)),
                );
            }
            $target->update(['status' => Target::PLANNING]);
            ProposePlanJob::dispatch($target, $module);

            return $this->dashboard->target($target->refresh(), $module);
        }, 202);
    }

    /** Approve the plan (selected scenarios, edits, additions) and queue generation. */
    public function approvePlan(Request $request, Target $target, Plan $plan, PlanService $plans, GenerationService $generation): JsonResponse
    {
        $data = $request->validate([
            'selected' => ['required', 'array', 'min:1'],
            'selected.*' => ['string', 'regex:/^S\d{1,3}$/'],
            'edits' => ['array'],
            'edits.*.id' => ['required', 'string', 'regex:/^S\d{1,3}$/'],
            'edits.*.name' => ['sometimes', 'string', 'max:200'],
            'edits.*.expected' => ['sometimes', 'string', 'max:1000'],
            'edits.*.inputs' => ['sometimes', 'string', 'max:1000'],
            'additions' => ['array', 'max:10'],
            'additions.*' => ['string', 'max:500'],
        ]);

        abort_unless($plan->target_id === $target->id, 404);

        return $this->attempt(function () use ($plans, $generation, $plan, $data, $target): array {
            $approved = $plans->approve($plan, $data['selected'], $data['edits'] ?? [], $data['additions'] ?? []);
            $test = $generation->start($approved);
            GenerateTestsJob::dispatch($test);

            return $this->dashboard->target($target->refresh(), $plan->module);
        }, 202);
    }
}
