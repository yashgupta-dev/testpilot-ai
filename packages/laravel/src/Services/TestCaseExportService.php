<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Support\Collection;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Plan;
use TestPilot\Laravel\Models\RunResult;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Support\SpreadsheetWriter;

/**
 * Exports test cases as a spreadsheet for QA, managers and audits: one row per planned case with where it came from,
 * the evidence for it, the generated test's status and its last result.
 *
 *   Test cases   — every case of the latest plan per target and module
 *   Summary      — one row per target and module
 *   AI rule gaps — findings from the validation AI review (only when there are any)
 */
final class TestCaseExportService
{
    public const FORMATS = ['xlsx', 'csv'];

    private const CASE_COLUMNS = [
        ['Area', 16], ['Target', 42], ['Module', 11], ['Case ID', 8], ['Test case', 48], ['Type', 10],
        ['Field', 16], ['Rule', 22], ['Input', 26], ['Expected', 34], ['Basis', 11], ['Evidence', 46],
        ['Confidence', 11], ['Priority', 9], ['Included', 9], ['Source', 16], ['Needs a human', 36],
        ['Plan status', 12], ['Approved by', 18], ['Test status', 14], ['Last result', 12], ['Last run', 18], ['Test file', 46],
    ];

    /**
     * @param list<string>|null $targetIds Limit to these targets.
     * @param bool              $selectedOnly Only cases included in the plan.
     *
     * @return array{filename: string, mime: string, body: string, rows: int}
     */
    public function export(string $format = 'xlsx', ?string $module = null, ?array $targetIds = null, bool $selectedOnly = false): array
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new TestPilotException('Export format must be ' . implode(' or ', self::FORMATS) . '.');
        }
        if ($module !== null && ! in_array($module, PlanService::MODULES, true)) {
            throw new TestPilotException('Unknown module "' . $module . '". Use ' . implode(', ', PlanService::MODULES) . '.');
        }

        $sheets = $this->sheets($module, $targetIds, $selectedOnly);
        $stamp = now()->format('Y-m-d_His');
        $name = 'testpilot-test-cases' . ($module === null ? '' : "-{$module}") . "_{$stamp}";

        return $format === 'csv'
            ? ['filename' => "{$name}.csv", 'mime' => 'text/csv; charset=UTF-8', 'body' => SpreadsheetWriter::csv($sheets['Test cases']), 'rows' => count($sheets['Test cases']['rows'])]
            : ['filename' => "{$name}.xlsx", 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'body' => SpreadsheetWriter::xlsx($sheets), 'rows' => count($sheets['Test cases']['rows'])];
    }

    /**
     * @param list<string>|null $targetIds
     *
     * @return array<string, array{columns: list<array{0: string, 1: int}>, rows: list<list<mixed>>}>
     */
    public function sheets(?string $module = null, ?array $targetIds = null, bool $selectedOnly = false): array
    {
        $targets = Target::query()
            ->whereNull('removed_at')
            ->when($targetIds !== null, fn ($q) => $q->whereIn('id', $targetIds))
            ->orderByDesc('risk_score')
            ->get()
            ->keyBy('id');

        // Latest usable plan per target + module (approved or proposed; superseded and failed plans are history).
        $plans = Plan::query()
            ->whereIn('target_id', $targets->keys())
            ->whereIn('status', ['approved', 'proposed'])
            ->when($module !== null, fn ($q) => $q->where('module', $module))
            ->orderByDesc('created_at')
            ->get()
            ->unique(fn (Plan $p): string => $p->target_id . '|' . $p->module);

        $tests = GeneratedTest::query()
            ->whereIn('plan_id', $plans->pluck('id'))
            ->orderByDesc('created_at')
            ->get()
            ->unique('plan_id')
            ->keyBy('plan_id');

        $results = $this->latestResults($tests);

        $cases = [];
        $summary = [];
        $gaps = [];

        foreach ($plans->sortBy(fn (Plan $p): string => sprintf('%03d|%s|%s', 999 - (int) $targets[$p->target_id]->risk_score, $targets[$p->target_id]->key, $p->module)) as $plan) {
            /** @var Target $target */
            $target = $targets[$plan->target_id];
            $test = $tests[$plan->id] ?? null;
            $counts = ['planned' => 0, 'included' => 0, 'needs_human' => 0, 'passed' => 0, 'failed' => 0];

            foreach ((array) $plan->scenarios as $s) {
                $meta = (array) ($s['meta'] ?? []);
                $todo = $meta['todo'] ?? null;
                $included = (bool) ($s['selected'] ?? false) && $todo === null;
                $result = $test === null ? null : ($results[$test->id][(string) $s['id']] ?? null);

                $counts['planned']++;
                $counts['included'] += $included ? 1 : 0;
                $counts['needs_human'] += $todo !== null ? 1 : 0;
                $counts['passed'] += ($result['status'] ?? null) === 'passed' ? 1 : 0;
                $counts['failed'] += ($result['status'] ?? null) === 'failed' ? 1 : 0;

                if ($selectedOnly && ! $included) {
                    continue;
                }

                $cases[] = [
                    $target->area,
                    $target->key,
                    $plan->module,
                    (string) $s['id'],
                    (string) ($s['name'] ?? ''),
                    (string) ($s['type'] ?? ''),
                    $meta['field'] ?? null,
                    $meta['rule'] ?? null,
                    (string) ($s['inputs'] ?? ''),
                    (string) ($s['expected'] ?? ''),
                    (string) ($s['basis'] ?? ''),
                    (string) ($s['basis_source'] ?? ''),
                    isset($s['confidence']) ? round((float) $s['confidence'], 2) : null,
                    (string) ($s['priority'] ?? ''),
                    $included ? 'yes' : 'no',
                    $this->source($plan, $s),
                    $todo,
                    $plan->status,
                    $plan->approved_by,
                    $test?->status ?? 'not generated',
                    $result['status'] ?? null,
                    $result['at'] ?? null,
                    $test?->approved_path ?? $test?->pending_path,
                ];
            }

            $summary[] = [
                $target->area,
                $target->key,
                $plan->module,
                (int) $target->risk_score,
                $target->criticality,
                $counts['planned'],
                $counts['included'],
                $counts['needs_human'],
                $counts['passed'],
                $counts['failed'],
                $plan->status,
                $test?->status ?? 'not generated',
                $test?->mutation_score === null ? null : (float) $test->mutation_score,
                $plan->model ?? 'no AI',
            ];

            foreach ((array) ($plan->context['ai']['gaps'] ?? []) as $gap) {
                $gaps[] = [$target->key, $gap['severity'] ?? '', $gap['field'] ?? '', $gap['issue'] ?? '', $gap['suggestion'] ?? '', $gap['basis_source'] ?? '', $plan->model];
            }
        }

        $sheets = [
            'Test cases' => ['columns' => self::CASE_COLUMNS, 'rows' => $cases],
            'Summary' => ['columns' => [
                ['Area', 16], ['Target', 42], ['Module', 11], ['Risk', 7], ['Criticality', 11], ['Cases', 8], ['Included', 9],
                ['Needs a human', 13], ['Passed', 8], ['Failed', 8], ['Plan status', 12], ['Test status', 14], ['Mutation %', 11], ['Planned with', 22],
            ], 'rows' => $summary],
        ];
        if ($gaps !== []) {
            $sheets['AI rule gaps'] = ['columns' => [['Target', 42], ['Severity', 9], ['Field', 16], ['Issue', 52], ['Suggestion', 36], ['Evidence', 30], ['Model', 20]], 'rows' => $gaps];
        }

        return $sheets;
    }

    /** @param array<string, mixed> $scenario */
    private function source(Plan $plan, array $scenario): string
    {
        $meta = (array) ($scenario['meta'] ?? []);

        return match (true) {
            str_starts_with((string) ($scenario['basis_source'] ?? ''), 'Added by reviewer') => 'Reviewer',
            (bool) ($meta['ai'] ?? false) => ($meta['verified'] ?? false) ? 'AI (checked)' : 'AI (assumption)',
            in_array($plan->module, PlanService::DETERMINISTIC, true) => $plan->module === 'validation' ? 'Rules' : 'Throttle config',
            default => 'AI plan',
        };
    }

    /**
     * Latest result per generated test and scenario id.
     *
     * @param Collection<string, GeneratedTest> $tests
     *
     * @return array<string, array<string, array{status: string, at: ?string}>>
     */
    private function latestResults(Collection $tests): array
    {
        $out = [];
        RunResult::query()
            ->whereIn('generated_test_id', $tests->pluck('id'))
            ->whereNotNull('scenario_id')
            ->orderBy('created_at')
            ->get(['generated_test_id', 'scenario_id', 'status', 'created_at'])
            ->each(function (RunResult $r) use (&$out): void {
                $out[$r->generated_test_id][$r->scenario_id] = ['status' => (string) $r->status, 'at' => $r->created_at?->format('Y-m-d H:i')];
            });

        return $out;
    }
}
