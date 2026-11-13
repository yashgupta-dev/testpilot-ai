<?php

declare(strict_types=1);

namespace TestPilot\Verification;

use Closure;
use TestPilot\Configuration\ResolvedConfig;
use TestPilot\DTO\TargetType;
use TestPilot\DTO\TestTarget;
use TestPilot\Exceptions\InvalidAiResponseException;
use TestPilot\Execution\MutationReport;
use TestPilot\Execution\PestRunner;
use TestPilot\Execution\RunResult;
use TestPilot\Generation\ScenarioPlan;
use TestPilot\Generation\TestCodeGenerator;
use TestPilot\Security\CodeSafetyChecker;
use TestPilot\Storage\TestFileWriter;

/**
 * Generate → safety check → lint → run → repair loop → stability → mutation gate.
 *
 * The result is a candidate in the pending area. Nothing reaches the test suite without a human approving it.
 */
final class GenerationPipeline
{
    /** @var (Closure(string, string): void)|null */
    private ?Closure $progress = null;

    /** True while repairing feature tests, so the repair prompt keeps the HTTP rules. */
    private bool $featureRepairs = false;

    /** Set when the AI could not be reached during a repair (rate limit, outage): stop asking it again. */
    private bool $aiUnavailable = false;

    public function __construct(
        private readonly TestCodeGenerator $generator,
        private readonly PestRunner $runner,
        private readonly TestFileWriter $writer,
        private readonly CodeSafetyChecker $safety = new CodeSafetyChecker(),
        private readonly int $maxRepairs = 3,
        private readonly int $stabilityRuns = 3,
        private readonly bool $mutationEnabled = true,
        private readonly float $minMutationScore = 60.0,
    ) {
    }

    /** @param Closure(string $stage, string $message): void $callback */
    public function onProgress(Closure $callback): self
    {
        $this->progress = $callback;

        return $this;
    }

    /** @param array<string, scalar|null> $provenance */
    public function run(TestTarget $target, ResolvedConfig $config, ScenarioPlan $plan, array $provenance = []): GenerationOutcome
    {
        $stages = [];
        $this->report('generate', "Generating Pest tests for {$target->key}");

        $generated = $this->generator->generate($target, $config, $plan);
        $code = $generated['code'];
        $stages[] = new StageResult('generate', StageResult::PASSED, count($plan->selected()) . ' approved scenarios sent to ' . $generated['model'], ['model' => $generated['model']]);

        $provenance = [
            'target' => $target->key,
            'source-hash' => $target->fingerprint,
            'model' => $generated['model'],
            'prompt' => 'plan@' . $plan->promptVersion . ', generate@' . $plan->promptVersion,
            'schema' => $plan->schemaVersion,
            'fuzz-seed' => $generated['fuzz_seed'],
            'generated' => gmdate('Y-m-d\TH:i:s\Z'),
            ...$provenance,
            'status' => 'pending-review',
        ];

        return $this->verify($target, $code, $stages, $provenance, 'Unit', $generated['fuzz_seed'], $generated['model'], $this->maxRepairs, $this->mutationEnabled, false);
    }

    /**
     * HTTP feature tests for a controller action: AI-generated, then verified exactly like unit tests
     * (safety → lint → run → repair → stability). Mutation testing is skipped: the mutated class would
     * be the whole controller and its collaborators, which is slow and noisy at HTTP level.
     */
    public function runFeature(TestTarget $target, ResolvedConfig $config, ScenarioPlan $plan, string $httpContext, array $provenance = []): GenerationOutcome
    {
        $this->report('generate', "Generating Pest feature tests for {$target->key}");

        $generated = $this->generator->generateFeature($target, $config, $plan, $httpContext);
        $stages = [new StageResult('generate', StageResult::PASSED, count($plan->selected()) . ' approved HTTP scenarios sent to ' . $generated['model'], ['model' => $generated['model']])];

        $provenance = [
            'target' => $target->key,
            'source-hash' => $target->fingerprint,
            'model' => $generated['model'],
            'prompt' => 'feature.plan@' . $plan->promptVersion . ', feature.generate@' . $plan->promptVersion,
            'schema' => $plan->schemaVersion,
            'generated' => gmdate('Y-m-d\TH:i:s\Z'),
            ...$provenance,
            'status' => 'pending-review',
        ];

        $this->featureRepairs = true;
        try {
            return $this->verify($target, $generated['code'], $stages, $provenance, 'Feature', null, $generated['model'], $this->maxRepairs, false, false);
        } finally {
            $this->featureRepairs = false;
        }
    }

    /**
     * Verifies code that was produced without AI (e.g. deterministic validation tests). No repair loop:
     * when some cases pass and others fail, the failures are reported as suspected bugs in the application,
     * because each expectation comes straight from the source code.
     *
     * $unconfirmed lists scenario ids whose expectation is an AI reading of a custom rule (an assumption). When one
     * of them fails, the reason says the AI may have misread the rule, instead of "declared but not enforced".
     *
     * @param array<string, scalar|null> $provenance
     * @param list<string>               $unconfirmed
     */
    public function runPrepared(TestTarget $target, string $code, string $module, string $summary, array $provenance = [], array $unconfirmed = [], ?string $aiModel = null): GenerationOutcome
    {
        $stages = [new StageResult('generate', StageResult::PASSED, $summary, ['model' => $aiModel === null ? 'deterministic' : "deterministic + {$aiModel}"])];
        $provenance = [
            'target' => $target->key,
            'source-hash' => $target->fingerprint,
            'generator' => strtolower($module) . '@v1 ' . ($aiModel === null ? '(deterministic, no AI)' : "(deterministic; AI-proposed values from {$aiModel})"),
            'generated' => gmdate('Y-m-d\TH:i:s\Z'),
            ...$provenance,
            'status' => 'pending-review',
        ];

        return $this->verify($target, $code, $stages, $provenance, $module, null, null, 0, false, true, $unconfirmed);
    }

    /**
     * @param list<StageResult>          $stages
     * @param array<string, scalar|null> $provenance
     */
    private function verify(
        TestTarget $target,
        string $code,
        array $stages,
        array $provenance,
        string $module,
        ?int $fuzzSeed,
        ?string $model,
        int $maxRepairs,
        bool $mutation,
        bool $failuresAreFindings,
        array $unconfirmed = [],
    ): GenerationOutcome {
        $pendingPath = $this->writer->pendingPath($target, $module);
        $repairs = 0;
        $this->aiUnavailable = false;
        $run = null;

        while (true) {
            $violations = $this->safety->check($code);
            if ($violations !== []) {
                $syntaxOnly = array_filter($violations, static fn (array $v): bool => $v['rule'] !== 'syntax') === [];
                if (! $syntaxOnly || ($repairs >= $maxRepairs || $this->aiUnavailable)) {
                    $stages[] = new StageResult('safety', StageResult::FAILED, count($violations) . ' safety violation(s); the code was not executed', ['violations' => $violations]);
                    $this->writer->discard($pendingPath);

                    return new GenerationOutcome(GenerationOutcome::UNSAFE, $code, null, $stages, $repairs, null, null, $fuzzSeed, $model);
                }

                $this->report('repair', 'Fixing a syntax error');
                $code = $this->repair($target, $code, $violations[0]['message'], $repairs, $stages);
                continue;
            }

            $this->writer->writePending($pendingPath, $code, $provenance);

            $lintError = $this->runner->lint($pendingPath);
            if ($lintError !== null) {
                if (($repairs >= $maxRepairs || $this->aiUnavailable)) {
                    $stages[] = new StageResult('lint', StageResult::FAILED, 'php -l failed', ['error' => $lintError]);
                    break;
                }
                $code = $this->repair($target, $code, $lintError, $repairs, $stages);
                continue;
            }

            $this->report('run', 'Running the generated tests');
            $run = $this->runner->run([$pendingPath]);

            if ($run->passed() || $this->onlySuspectedBugsFail($run, $code) || ($repairs >= $maxRepairs || $this->aiUnavailable)) {
                break;
            }

            $this->report('repair', 'Tests failed; asking the AI to repair the test (attempt ' . ($repairs + 1) . ')');
            $code = $this->repair($target, $code, $run->failureSummary(), $repairs, $stages);
        }

        $suspected = $this->suspectedBugs($code);

        // A 404 means the test could not reach the endpoint (URL parameters did not resolve to records).
        // That is a setup problem in the generated test, never evidence of a bug in the application.
        $notFound = false;
        foreach ($run?->failed() ?? [] as $case) {
            if (preg_match('/\b(?:received|got) 404\b/', (string) ($case->details ?? $case->message ?? '')) === 1) {
                $notFound = true;
            }
        }

        // Every case failing on the same environment error (no DB driver, DB unreachable, missing APP_KEY) is a problem
        // with the test setup, not with the application: say what to fix instead of reporting failures or bugs.
        $environment = $run === null ? null : self::environmentProblem($run);
        if ($environment !== null) {
            $notFound = true; // never turn these failures into suspected bugs
        }

        if ($failuresAreFindings && ! $notFound && $run !== null && ! $run->passed() && ! $run->timedOut && $run->count('passed') > 0) {
            foreach ($run->failed() as $case) {
                if ($case->scenarioId() !== null) {
                    $text = (string) ($case->details ?? $case->message ?? 'assertion failed');
                    $text = str_starts_with($text, $case->name) ? substr($text, strlen($case->name)) : $text;
                    $suspected[$case->scenarioId()] = (in_array($case->scenarioId(), $unconfirmed, true)
                        ? 'AI-proposed case failed: the AI may have misread the custom rule, or the rule is not enforced: '
                        : 'Declared in the source but not enforced: ') . trim((string) strtok(trim($text), "\n"));
                }
            }
        }
        $findings = $failuresAreFindings && $suspected !== [] && count($suspected) === count($run?->failed() ?? []);

        if ($run === null) {
            return new GenerationOutcome(GenerationOutcome::FAILED, $code, $pendingPath, $stages, $repairs, null, null, $fuzzSeed, $model);
        }

        $stages[] = new StageResult('safety', StageResult::PASSED, 'No forbidden constructs found');
        $stages[] = new StageResult(
            'run',
            $run->passed() ? StageResult::PASSED : StageResult::FAILED,
            sprintf('%d passed, %d failed, %d assertions in %.0f ms', $run->count('passed'), count($run->failed()), $run->assertions(), $run->durationMs),
            ['command' => $run->command],
        );

        if (! $run->passed() && $environment !== null) {
            $stages[] = new StageResult('setup', StageResult::FAILED, $environment);

            return new GenerationOutcome(GenerationOutcome::FAILED, $code, $pendingPath, $stages, $repairs, $run, null, $fuzzSeed, $model);
        }

        if (! $run->passed() && $notFound) {
            $stages[] = new StageResult('setup', StageResult::FAILED, 'The route answered 404 Not Found, so the URL parameters did not resolve to records. Check the factories and scoped relationships; this is not reported as a bug in the application.');

            return new GenerationOutcome(GenerationOutcome::FAILED, $code, $pendingPath, $stages, $repairs, $run, null, $fuzzSeed, $model);
        }

        if (! $run->passed() && ! $findings && ! $this->onlySuspectedBugsFail($run, $code)) {
            return new GenerationOutcome(GenerationOutcome::FAILED, $code, $pendingPath, $stages, $repairs, $run, null, $fuzzSeed, $model, $suspected);
        }

        // Stability: the same file must give the same results in random order.
        $this->report('stability', "Re-running {$this->stabilityRuns}× in random order");
        $baseline = $this->signature($run);
        $stable = true;
        for ($i = 1; $i < $this->stabilityRuns; $i++) {
            if ($this->signature($this->runner->run([$pendingPath], ['--order-by=random'])) !== $baseline) {
                $stable = false;
                break;
            }
        }
        $stages[] = new StageResult(
            'stability',
            $stable ? StageResult::PASSED : StageResult::FAILED,
            $stable ? "{$this->stabilityRuns}/{$this->stabilityRuns} runs identical" : 'Results changed between runs (flaky)',
        );

        if (! $stable) {
            return new GenerationOutcome(GenerationOutcome::FAILED, $code, $pendingPath, $stages, $repairs, $run, null, $fuzzSeed, $model, $suspected);
        }

        $mutationReport = $mutation ? $this->mutation($target, $pendingPath, $run, $stages) : null;
        if (! $mutation) {
            $stages[] = new StageResult('mutation', StageResult::SKIPPED, $module === 'Unit' ? 'Mutation testing disabled' : 'Not applicable to ' . strtolower($module) . ' tests');
        }
        $status = $run->passed() ? GenerationOutcome::VERIFIED : GenerationOutcome::SUSPECTED_BUG;

        return new GenerationOutcome($status, $code, $pendingPath, $stages, $repairs, $run, $mutationReport, $fuzzSeed, $model, $suspected);
    }

    /** @param list<StageResult> $stages */
    private function repair(TestTarget $target, string $code, string $failure, int &$repairs, array &$stages): string
    {
        $repairs++;
        try {
            $fixed = $this->generator->repair($target, $code, $failure, $this->featureRepairs, $repairs);
            $stages[] = new StageResult('repair', StageResult::WARNING, "Repair attempt {$repairs}", ['failure' => mb_substr($failure, 0, 1500)]);

            return $fixed;
        } catch (InvalidAiResponseException $e) {
            $stages[] = new StageResult('repair', StageResult::FAILED, "Repair attempt {$repairs} returned no usable code", ['error' => $e->getMessage()]);

            return $code;
        } catch (\TestPilot\Exceptions\AiProviderException $e) {
            // Rate limit or outage after the HTTP client's retries: keep the result so far instead of failing the job.
            $this->aiUnavailable = true;
            $stages[] = new StageResult('repair', StageResult::FAILED, "Repair attempt {$repairs} could not reach the AI; repairs stopped. Generate again in a minute.", ['error' => mb_substr($e->getMessage(), 0, 1000)]);

            return $code;
        }
    }

    /** @param list<StageResult> $stages */
    private function mutation(TestTarget $target, string $pendingPath, RunResult $run, array &$stages): ?MutationReport
    {
        if (! $this->mutationEnabled || $target->type !== TargetType::Method || ! $run->passed()) {
            $stages[] = new StageResult('mutation', StageResult::SKIPPED, $target->type === TargetType::Function
                ? 'Pest mutation testing targets classes; skipped for functions'
                : 'Mutation testing skipped');

            return null;
        }

        $this->report('mutation', 'Injecting bugs to check the tests catch them (pest --mutate)');
        $report = $this->runner->mutate($pendingPath);

        if ($report->score === null) {
            $stages[] = new StageResult('mutation', StageResult::WARNING, $report->error ?? 'Mutation output could not be parsed; see raw output', ['output' => mb_substr($report->output, -3000)]);
        } else {
            $ok = $report->score >= $this->minMutationScore;
            $stages[] = new StageResult(
                'mutation',
                $ok ? StageResult::PASSED : StageResult::WARNING,
                sprintf('Mutation score %.1f%% (gate %.0f%%)%s', $report->score, $this->minMutationScore, $ok ? '' : ' — tests are weak; strengthen before approving'),
                $report->toArray(),
            );
        }

        return $report;
    }

    /** A setup or discovery problem shared by the run, described with the fix; null when failures look like real results. */
    public static function environmentProblem(RunResult $run): ?string
    {
        $failed = $run->failed();
        if ($failed === [] && $run->exitCode === 0) {
            $withoutAssertions = array_filter(
                $run->cases,
                static fn ($case): bool => $case->passed() && $case->assertions === 0,
            );
            if ($run->cases === []) {
                return 'Pest exited successfully but reported no test cases. The generated tests were not discovered, so no scenario was executed. Check the Pest test paths and configuration; this is not evidence that the application behavior passed.';
            }
            if ($run->count('passed') === 0) {
                return 'Pest exited successfully but no test case passed (the cases may have been skipped). No scenario was verified; check test discovery and skip conditions.';
            }
            if ($withoutAssertions !== []) {
                return 'Pest exited successfully but one or more tests reported zero assertions. No proof was produced for those scenarios. Add assertions and rerun; this is not evidence that the application behavior passed.';
            }
        }

        if ($failed === [] || $run->count('passed') > 0) {
            return null;
        }

        $known = [
            '/could not find driver.*?Connection: (\w+)/s' => 'PHP has no PDO driver for the "%s" test database, so no test could boot. Install it (e.g. sudo apt install php8.4-sqlite3) or point DB_CONNECTION in phpunit.xml at a test database. Not reported as a bug in the application.',
            '/SQLSTATE\[HY000\] \[2002\]/' => 'The test database is unreachable (connection refused). Start it or change DB_* in phpunit.xml. Not reported as a bug in the application.',
            '/SQLSTATE\[HY000\] \[1045\]/' => 'The test database rejected the credentials in phpunit.xml / .env.testing. Not reported as a bug in the application.',
            '/SQLSTATE\[HY000\] \[1049\]/' => 'The test database in phpunit.xml / .env.testing does not exist. Create it first. Not reported as a bug in the application.',
            '/no such table:\s*["`]?([^\s"`;]+)/i' => 'The test database schema is missing table "%s". Run the application and module migrations for the test environment, then rerun; this result cannot confirm the target behavior.',
            '/Table\s+["`]?([^"`\s]+)["`]?\s+doesn\'t exist/i' => 'The test database schema is missing table "%s". Run the application and module migrations for the test environment, then rerun; this result cannot confirm the target behavior.',
            '/relation\s+["\']([^"\']+)["\']\s+does not exist/i' => 'The test database schema is missing table "%s". Run the application and module migrations for the test environment, then rerun; this result cannot confirm the target behavior.',
            '/no such column:\s*["`]?([^\s"`;]+)/i' => 'The test database schema is missing column "%s". Run the application and module migrations for the test environment, then rerun; this result cannot confirm the target behavior.',
            '/No application encryption key has been specified/' => 'APP_KEY is missing in the test environment (.env.testing or phpunit.xml). Not reported as a bug in the application.',
        ];

        foreach ($known as $pattern => $text) {
            $matches = 0;
            $captured = null;
            foreach ($failed as $case) {
                if (preg_match($pattern, (string) ($case->details ?? '') . ' ' . (string) ($case->message ?? ''), $m) === 1) {
                    $matches++;
                    $captured ??= $m[1] ?? null;
                }
            }
            if ($matches === count($failed)) {
                return sprintf($text, $captured ?? 'configured');
            }
        }

        return null;
    }

    private function onlySuspectedBugsFail(RunResult $run, string $code): bool
    {
        $failed = $run->failed();
        if ($failed === [] || $run->timedOut) {
            return false;
        }

        $suspected = $this->suspectedBugs($code);
        foreach ($failed as $case) {
            $id = $case->scenarioId();
            if ($id === null || ! isset($suspected[$id])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function suspectedBugs(string $code): array
    {
        preg_match_all('#//\s*@testpilot-suspected-bug\s+(S\d{1,3})\s*:\s*(.+)$#m', $code, $matches, PREG_SET_ORDER);
        $found = [];
        foreach ($matches as $match) {
            $found[$match[1]] = trim($match[2]);
        }

        return $found;
    }

    private function signature(RunResult $run): string
    {
        $parts = array_map(static fn ($c): string => $c->name . '=' . $c->status, $run->cases);
        sort($parts);

        return implode('|', $parts) . '#' . ($run->timedOut ? 'timeout' : 'ok');
    }

    private function report(string $stage, string $message): void
    {
        if ($this->progress !== null) {
            ($this->progress)($stage, $message);
        }
    }
}
