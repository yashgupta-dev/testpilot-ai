<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Plan;
use TestPilot\Laravel\Models\Run;
use TestPilot\Laravel\Models\RunResult;
use TestPilot\Laravel\Models\Target;

/**
 * Read models for the dashboard API. Every number shown in the UI comes from here.
 */
final class DashboardService
{
    public function __construct(
        private readonly TestPilotManager $manager,
        private readonly EloquentUsageRecorder $usage,
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $active = Target::query()->active();
        $total = (clone $active)->count();
        $byStatus = (clone $active)->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status')->all();
        $withApproved = (clone $active)->whereIn('status', [Target::APPROVED])->count();

        $lastRuns = Run::query()->latest('created_at')->limit(5)->get();
        $last = $lastRuns->firstWhere('status', '!=', Run::QUEUED);
        $passRate = $last !== null && ($last->passed + $last->failed) > 0 ? round($last->passed / ($last->passed + $last->failed) * 100, 1) : null;
        $mutation = GeneratedTest::query()->where('status', GeneratedTest::APPROVED)->whereNotNull('mutation_score')->avg('mutation_score');

        $trend = Run::query()
            ->whereIn('status', [Run::COMPLETED, Run::FAILED])
            ->where('created_at', '>=', Carbon::now()->subWeeks(8)->startOfWeek())
            ->get(['passed', 'failed', 'created_at'])
            ->groupBy(fn (Run $r): string => $r->created_at->startOfWeek()->format('Y-m-d'))
            ->map(fn ($runs, $week): array => [
                'week' => $week,
                'pass_rate' => ($p = $runs->sum('passed')) + ($f = $runs->sum('failed')) > 0 ? round($p / ($p + $f) * 100, 1) : null,
                'runs' => $runs->count(),
            ])->values()->all();

        $budget = (float) config('testpilot.ai.monthly_budget', 0);

        return [
            'last_scan' => cache('testpilot.last_scan'),
            'stats' => [
                'targets' => $total,
                'with_approved_tests' => $withApproved,
                'coverage_of_intent' => $total > 0 ? round($withApproved / $total * 100) : 0,
                'pass_rate' => $passRate,
                'mutation_score' => $mutation === null ? null : round((float) $mutation, 1),
                'awaiting_approval' => GeneratedTest::query()->whereIn('status', [GeneratedTest::VERIFIED, GeneratedTest::SUSPECTED_BUG])->count(),
                'failing_targets' => (clone $active)->where('last_result', 'failed')->count(),
                'stale' => (clone $active)->where('status', Target::STALE)->count(),
            ],
            'by_status' => $byStatus,
            'trend' => $trend,
            'recent_runs' => $lastRuns->map(fn (Run $r): array => $this->runSummary($r))->all(),
            'ai' => [
                'spent' => round($this->usage->spentThisMonth(), 2),
                'budget' => $budget,
            ],
            'environment' => $this->environment(),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function targets(array $filters): array
    {
        $query = Target::query()->active()->with('latestTest');

        if (! empty($filters['status'])) {
            $status = (string) $filters['status'];
            $status === 'failing' ? $query->where('last_result', 'failed') : $query->where('status', $status);
        }
        if (! empty($filters['search'])) {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']) . '%';
            $query->where(fn ($q) => $q->where('key', 'like', $term)->orWhere('file', 'like', $term));
        }
        if (! empty($filters['area'])) {
            $query->where('area', (string) $filters['area']);
        }

        $counts = Target::query()->active()->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status')->all();

        return [
            'data' => $query->orderByDesc('risk_score')->orderBy('key')->limit(500)->get()->map(fn (Target $t): array => $this->targetSummary($t))->all(),
            'counts' => [
                'all' => array_sum($counts),
                'failing' => Target::query()->active()->where('last_result', 'failed')->count(),
                ...$counts,
            ],
            'areas' => Target::query()->active()->whereNotNull('area')->distinct()->orderBy('area')->pluck('area')->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function target(Target $target, ?string $module = null): array
    {
        $available = PlanService::plannableModules($target);
        if (! in_array($module, $available, true)) {
            // Open on the module someone last worked on, so a validation-only workflow is not hidden behind "Unit".
            $recent = $target->generatedTests()->whereIn('module', $available)->latest()->value('module')
                ?? $target->plans()->whereIn('module', $available)->latest()->value('module');
            $module = $recent ?? ($available[0] ?? 'unit');
        }
        $plan = $target->plans()->where('module', $module)->first();
        $test = $target->generatedTests()->where('module', $module)->first();
        $results = RunResult::query()->where('target_id', $target->id)->with('run:id,number,created_at')->latest()->limit(50)->get();

        return [
            ...$this->targetSummary($target),
            'signature' => $target->signature,
            'source' => $target->source,
            'end_line' => $target->end_line,
            'config' => $target->config,
            'risk_reasons' => $target->risk_reasons,
            'route' => $target->route,
            'annotations' => [
                'class' => $target->data['class_annotations'] ?? [],
                'method' => $target->data['annotations'] ?? [],
            ],
            'module' => $module,
            'plannable_modules' => $available,
            'modules_summary' => collect($available)->mapWithKeys(fn (string $m): array => [$m => [
                'plan' => $target->plans()->where('module', $m)->value('status'),
                'test' => $target->generatedTests()->where('module', $m)->value('status'),
            ]])->all(),
            'plan' => $plan === null ? null : $this->plan($plan),
            'test' => $test === null ? null : $this->test($test),
            'history' => $results->map(fn (RunResult $r): array => [
                'run' => $r->run?->number,
                'run_id' => $r->run_id,
                'at' => $r->created_at?->toIso8601String(),
                'scenario_id' => $r->scenario_id,
                'name' => $r->name,
                'status' => $r->status,
                'time_ms' => $r->time_ms,
                'message' => $r->message,
            ])->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function reviewQueue(): array
    {
        return GeneratedTest::query()
            ->whereIn('status', [GeneratedTest::VERIFIED, GeneratedTest::SUSPECTED_BUG, GeneratedTest::FAILED, GeneratedTest::UNSAFE, GeneratedTest::GENERATING])
            ->with('target')
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (GeneratedTest $t): array => [...$this->test($t), 'target' => $t->target === null ? null : $this->targetSummary($t->target)])
            ->all();
    }

    /** @return array<string, mixed> */
    public function runs(): array
    {
        return ['data' => Run::query()->latest('number')->limit(100)->get()->map(fn (Run $r): array => $this->runSummary($r))->all()];
    }

    /** @return array<string, mixed> */
    public function run(Run $run): array
    {
        $results = $run->results()->with('target:id,key,class,method,function,type')->get();

        return [
            ...$this->runSummary($run),
            'outcome' => RunOutcomeExplainer::explain($run, $results->all()),
            'command' => $run->command,
            'output' => $run->output,
            'error' => $run->error,
            'results' => $results->map(fn (RunResult $r): array => [
                'id' => $r->id,
                'target_id' => $r->target_id,
                'target' => $r->target?->shortName(),
                'scenario_id' => $r->scenario_id,
                'name' => $r->name,
                'status' => $r->status,
                'time_ms' => $r->time_ms,
                'assertions' => $r->assertions,
                'message' => $r->message,
                'details' => $r->details,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function environment(): array
    {
        $core = $this->manager->core();
        $ai = (array) config('testpilot.ai');
        $queue = config('testpilot.queue.connection') ?? config('queue.default');
        $pestBinary = (array) config('testpilot.pest.binary', ['vendor/bin/pest']);
        $pestPath = (string) ($pestBinary[0] ?? 'vendor/bin/pest');
        $pestInstalled = is_file(str_starts_with($pestPath, DIRECTORY_SEPARATOR)
            ? $pestPath
            : $core->paths->root() . DIRECTORY_SEPARATOR . $pestPath);
        $testsAllowed = $core->environment->isAllowed();
        $phpSupported = PHP_VERSION_ID >= 80200;
        $laravelMajor = (int) explode('.', app()->version())[0];
        $laravelSupported = $laravelMajor >= 10 && $laravelMajor <= 13;
        $aiAllowed = $core->limits->aiAllowed && ($ai['policy'] ?? 'cloud') !== 'off';
        $aiKeySet = ! empty($ai['api_key']) || in_array($ai['driver'] ?? '', ['ollama', 'fake'], true);
        $testDatabase = app(TestDatabaseReadiness::class)->inspect($core->paths->root());

        return [
            'app_env' => app()->environment(),
            'tests_allowed' => $testsAllowed,
            'database' => config('database.default'),
            'test_database' => $testDatabase,
            'queue' => $queue,
            'queue_is_sync' => $queue === 'sync',
            'ai_driver' => $ai['driver'] ?? null,
            'ai_model' => $ai['model'] ?? null,
            'ai_policy' => $ai['policy'] ?? 'cloud',
            'ai_key_set' => $aiKeySet,
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'pest_installed' => $pestInstalled,
            'mutation_enabled' => (bool) config('testpilot.mutation.enabled'),
            'coverage_driver' => extension_loaded('pcov') ? 'pcov' : (extension_loaded('xdebug') ? 'xdebug' : null),
            'limits' => $core->limits->toArray(),
            'paths' => $core->scanPaths(),
            'generated_path' => config('testpilot.generated_path'),
            'readiness' => [
                'can_run_tests' => $testsAllowed && $pestInstalled && $phpSupported && $laravelSupported,
                'can_plan_with_ai' => $testsAllowed && $aiAllowed && $aiKeySet,
                'checks' => [
                    [
                        'id' => 'runtime',
                        'status' => $phpSupported && $laravelSupported ? 'ready' : 'blocked',
                        'title' => 'Supported runtime',
                        'detail' => "PHP {$this->versionLabel(PHP_VERSION)} · Laravel {$this->versionLabel(app()->version())}",
                        'action' => $phpSupported && $laravelSupported ? 'This version can run TestPilot.' : 'Use PHP 8.2+ and Laravel 10–13 for this release.',
                        'area' => 'run',
                    ],
                    [
                        'id' => 'safe_environment',
                        'status' => $testsAllowed ? 'ready' : 'blocked',
                        'title' => 'Safe environment',
                        'detail' => "Current environment: " . app()->environment(),
                        'action' => $testsAllowed ? 'Tests are allowed here.' : 'Switch to a non-production environment. TestPilot blocks test execution in production.',
                        'area' => 'run',
                    ],
                    [
                        'id' => 'pest',
                        'status' => $pestInstalled ? 'ready' : 'blocked',
                        'title' => 'Pest test runner',
                        'detail' => $pestInstalled ? 'The configured Pest executable was found.' : "Could not find {$pestPath} inside the project.",
                        'action' => $pestInstalled ? 'Approved tests can be executed.' : 'Install Pest in the project or correct testpilot.pest.binary.',
                        'area' => 'run',
                    ],
                    [
                        'id' => 'ai',
                        'status' => ! $aiAllowed ? 'blocked' : ($aiKeySet ? 'ready' : 'needs_setup'),
                        'title' => 'AI-assisted test planning',
                        'detail' => ! $aiAllowed ? 'AI use is disabled by project policy.' : ($aiKeySet ? "Provider: " . ($ai['driver'] ?? 'configured') : 'An AI provider credential is not configured.'),
                        'action' => ! $aiAllowed ? 'AI planning is disabled; deterministic validation and running approved tests can still work.' : ($aiKeySet ? 'New scenarios can be planned with AI.' : 'Configure an AI provider to create new AI-assisted plans. Existing approved tests can still run.'),
                        'area' => 'planning',
                    ],
                    [
                        'id' => 'queue',
                        'status' => $queue === 'sync' ? 'recommended' : 'ready',
                        'title' => 'Background processing',
                        'detail' => $queue === 'sync' ? 'Tasks run during the web request.' : "Queue connection: {$queue}",
                        'action' => $queue === 'sync' ? 'For a smoother dashboard, configure a queue worker. This is recommended, not required.' : 'Make sure a worker is running for the configured queue.',
                        'area' => 'recommended',
                    ],
                    [
                        'id' => 'database',
                        'status' => 'check_when_running',
                        'title' => 'Test database configuration',
                        'detail' => $testDatabase['detail'],
                        'action' => $testDatabase['action'],
                        'area' => 'run',
                    ],
                    [
                        'id' => 'mutation',
                        'status' => ! (bool) config('testpilot.mutation.enabled') || extension_loaded('pcov') || extension_loaded('xdebug') ? 'ready' : 'optional',
                        'title' => 'Mutation testing (optional)',
                        'detail' => extension_loaded('pcov') || extension_loaded('xdebug') ? 'A coverage extension is available.' : 'No PCOV or Xdebug coverage extension was detected.',
                        'action' => extension_loaded('pcov') || extension_loaded('xdebug') ? 'TestPilot can measure whether tests catch injected defects.' : 'Basic tests still run. Install PCOV or Xdebug if you want mutation scores.',
                        'area' => 'optional',
                    ],
                ],
            ],
        ];
    }

    private function versionLabel(string $version): string
    {
        return preg_replace('/^(\d+\.\d+).*/', '$1', $version) ?? $version;
    }

    /** @return array<string, mixed> */
    private function targetSummary(Target $t): array
    {
        return [
            'id' => $t->id,
            'key' => $t->key,
            'name' => $t->shortName(),
            'type' => $t->route !== null ? 'route' : $t->type,
            'file' => $t->file,
            'line' => $t->line,
            'area' => $t->area,
            'criticality' => $t->criticality,
            'modules' => $t->modules,
            'status' => $t->status,
            'enabled' => $t->enabled,
            'complexity' => $t->complexity,
            'risk' => ['score' => $t->risk_score, 'level' => $t->risk_level],
            'route' => $t->route === null ? null : ['methods' => $t->route['methods'], 'uri' => $t->route['uri'], 'throttle' => $t->route['throttle']],
            'last_result' => $t->last_result,
            'last_run_at' => $t->last_run_at?->toIso8601String(),
            'mutation_score' => $t->relationLoaded('latestTest') ? $t->latestTest?->mutation_score : null,
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function plan(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'module' => $plan->module,
            'context' => $plan->context,
            'status' => $plan->status,
            'scenarios' => $plan->scenarios,
            'notes' => $plan->notes,
            'model' => $plan->model,
            'prompt_version' => $plan->prompt_version,
            'approved_by' => $plan->approved_by,
            'approved_at' => $plan->approved_at?->toIso8601String(),
            'error' => $plan->error,
            'created_at' => $plan->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function test(GeneratedTest $test): array
    {
        return [
            'id' => $test->id,
            'module' => $test->module,
            'target_id' => $test->target_id,
            'status' => $test->status,
            'code' => $test->code,
            'pending_path' => $test->pending_path,
            'approved_path' => $test->approved_path,
            'verification' => $test->verification,
            'mutation_score' => $test->mutation_score,
            'fuzz_seed' => $test->fuzz_seed,
            'model' => $test->model,
            'repair_attempts' => $test->repair_attempts,
            'reviewed_by' => $test->reviewed_by,
            'reviewed_at' => $test->reviewed_at?->toIso8601String(),
            'rejection_reason' => $test->rejection_reason,
            'error' => $test->error,
            'scenarios' => $test->plan?->scenarios ?? [],
            'created_at' => $test->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function runSummary(Run $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'status' => $r->status,
            'outcome' => RunOutcomeExplainer::explain($r),
            'trigger' => $r->trigger,
            'git_sha' => $r->git_sha,
            'passed' => $r->passed,
            'failed' => $r->failed,
            'skipped' => $r->skipped,
            'assertions' => $r->assertions,
            'duration_ms' => $r->duration_ms,
            'started_by' => $r->started_by,
            'created_at' => $r->created_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
        ];
    }
}
