<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use TestPilot\Configuration\Module;
use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Support\TargetHydrator;
use TestPilot\Risk\RiskScorer;
use TestPilot\Risk\RiskSignals;

/**
 * Syncs discovered targets into the database: new targets are added, changed ones are re-fingerprinted
 * (their approved tests become stale), vanished ones are marked removed. Risk is recalculated every scan.
 */
final class ScanService
{
    public function __construct(
        private readonly TestPilotManager $manager,
        private readonly RouteInspector $routes,
        private readonly GitHistory $git,
        private readonly AuditLogger $audit,
        private readonly RiskScorer $risk = new RiskScorer(),
    ) {
    }

    /** @return array{targets: int, added: int, changed: int, removed: int, stale: int, warnings: list<string>, errors: list<string>, files: int, duration_ms: float} */
    public function scan(): array
    {
        $core = $this->manager->core();
        $result = $core->scanner->scan($core->scanPaths());

        $added = $changed = $stale = 0;
        $seen = [];

        DB::transaction(function () use ($result, $core, &$added, &$changed, &$stale, &$seen): void {
            foreach ($result->targets as $target) {
                $config = $core->resolver->resolve($target);
                $hash = hash('sha256', $target->key);
                $seen[] = $hash;

                /** @var Target|null $row */
                $row = Target::query()->where('key_hash', $hash)->first();
                $route = $target->class !== null && $target->method !== null ? $this->routes->forTarget($target->class, $target->method) : null;

                $hasApproved = $row !== null && GeneratedTest::query()
                    ->where('target_id', $row->id)
                    ->where('status', GeneratedTest::APPROVED)
                    ->where('source_fingerprint', $target->fingerprint)
                    ->exists();

                $assessment = $this->risk->score($target, new RiskSignals(
                    hasApprovedTests: $hasApproved,
                    recentChanges: $this->git->changes($target->location->file),
                    changeWindowDays: $this->git->windowDays(),
                    isPublicRoute: $route !== null && ! in_array('auth', $route['middleware'], true),
                    lastRunFailed: $row?->last_result === 'failed',
                ));

                $attributes = [
                    'key' => $target->key,
                    'type' => $target->type->value,
                    'class' => $target->class,
                    'method' => $target->method,
                    'function' => $target->function,
                    'file' => $target->location->file,
                    'line' => $target->location->startLine,
                    'end_line' => $target->location->endLine,
                    'signature' => $target->signature,
                    'fingerprint' => $target->fingerprint,
                    'complexity' => $target->complexity,
                    'area' => $config->area,
                    'criticality' => $config->criticality,
                    'enabled' => $config->enabled,
                    'modules' => array_map(static fn (Module $m): string => $m->value, $config->activeModules()),
                    'config' => $config->toArray(),
                    'data' => TargetHydrator::dehydrate($target),
                    'source' => $target->source,
                    'risk_score' => $assessment->score,
                    'risk_level' => $assessment->level,
                    'risk_reasons' => $assessment->reasons,
                    'route' => $route,
                    'removed_at' => null,
                ];

                if ($row === null) {
                    Target::query()->create([...$attributes, 'key_hash' => $hash, 'status' => $config->enabled ? Target::NOT_PLANNED : Target::SKIPPED]);
                    $added++;
                    continue;
                }

                if ($row->fingerprint !== $target->fingerprint) {
                    $changed++;
                    $stale += GeneratedTest::query()
                        ->where('target_id', $row->id)
                        ->where('status', GeneratedTest::APPROVED)
                        ->update(['status' => GeneratedTest::STALE]);
                    if ($row->status === Target::APPROVED) {
                        $attributes['status'] = Target::STALE;
                    }
                }

                if (! $config->enabled) {
                    $attributes['status'] = Target::SKIPPED;
                } elseif ($row->status === Target::SKIPPED) {
                    $attributes['status'] = Target::NOT_PLANNED;
                }

                $row->fill($attributes)->save();
            }
        });

        $removed = Target::query()->whereNull('removed_at')->whereNotIn('key_hash', $seen)->update(['removed_at' => Carbon::now()]);

        $summary = [
            'targets' => count($result->targets),
            'added' => $added,
            'changed' => $changed,
            'removed' => $removed,
            'stale' => $stale,
            'warnings' => $result->warnings,
            'errors' => $result->errors,
            'files' => $result->filesScanned,
            'duration_ms' => $result->durationMs,
        ];

        $this->audit->log('scan.completed', null, array_diff_key($summary, ['warnings' => 1, 'errors' => 1]));
        cache()->forever('testpilot.last_scan', ['at' => Carbon::now()->toIso8601String(), ...$summary]);

        return $summary;
    }
}
