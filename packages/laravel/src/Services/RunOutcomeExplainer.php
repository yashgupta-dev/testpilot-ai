<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use TestPilot\Laravel\Models\Run;

final class RunOutcomeExplainer
{
    /**
     * @param list<array<string, mixed>|object>|null $results Null for summaries without loaded results.
     *
     * @return array{code: string, title: string, summary: string, next_step: string, tone: string}
     */
    public static function explain(Run $run, ?array $results = null): array
    {
        if (in_array($run->status, [Run::QUEUED, Run::RUNNING], true)) {
            return self::outcome('in_progress', 'Tests are running', 'TestPilot is checking the approved scenarios now.', 'Keep this page open; it updates when the run finishes.', 'blue');
        }

        if ($run->status === Run::CANCELLED) {
            return self::outcome('cancelled', 'Run was cancelled', 'The tests were stopped before completion.', 'Start a new run when you are ready.', 'gray');
        }

        $results ??= [];
        $failed = array_values(array_filter($results, static fn (array|object $result): bool => in_array(self::value($result, 'status'), ['failed', 'error'], true)));
        $text = self::diagnosticText($run, $failed);

        foreach (self::setupProblems() as $pattern => $problem) {
            if ($failed !== [] && self::allMatch($failed, $pattern)) {
                return $problem;
            }
            if ($failed === [] && preg_match($pattern, $text) === 1) {
                return $problem;
            }
        }

        if ($run->error !== null && str_contains(mb_strtolower($run->error), 'timed out')) {
            return self::outcome('setup_problem', 'The test run timed out', 'TestPilot stopped the run because it took longer than the allowed time.', 'Ask a developer to check for slow or stuck tests, then try again.', 'amber');
        }

        if ($run->status === Run::COMPLETED && $run->assertions > 0 && $run->passed > 0) {
            return self::outcome('passed', 'All checked tests passed', "{$run->passed} tests passed and {$run->assertions} checks were made.", 'No action needed. Review the detailed results if you want to see each scenario.', 'green');
        }

        if ($run->assertions === 0 || ($run->passed === 0 && $run->failed === 0 && $run->skipped === 0)) {
            return self::outcome('not_verified', 'No behavior was verified', 'No test assertion completed, so this run cannot confirm that the application works as expected.', 'Check whether tests were discovered and whether the test file contains assertions, then run again.', 'amber');
        }

        if ($run->failed > 0 || $failed !== []) {
            $summary = $run->passed > 0
                ? "{$run->passed} tests passed, but {$run->failed} did not match their expected result."
                : "{$run->failed} tests did not match their expected result.";

            return self::outcome('application_failure', 'Some expected behavior did not pass', $summary, 'Ask a developer or QA reviewer to inspect the failed scenario and its technical details below.', 'red');
        }

        return self::outcome('not_verified', 'This run did not verify the expected behavior', 'The run did not complete with passing assertions.', 'Check the technical details below, correct the test setup, and run again.', 'amber');
    }

    /** @param list<array<string, mixed>|object> $results */
    private static function diagnosticText(Run $run, array $results): string
    {
        $parts = [$run->error, $run->output];
        foreach ($results as $result) {
            $parts[] = self::value($result, 'message');
            $parts[] = self::value($result, 'details');
        }

        return implode("\n", array_filter($parts, static fn ($part): bool => is_string($part) && $part !== ''));
    }

    /** @param list<array<string, mixed>|object> $results */
    private static function allMatch(array $results, string $pattern): bool
    {
        foreach ($results as $result) {
            $caseText = implode("\n", array_filter([
                self::value($result, 'message'),
                self::value($result, 'details'),
            ], static fn ($part): bool => is_string($part) && $part !== ''));

            if (preg_match($pattern, $caseText) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, array{code: string, title: string, summary: string, next_step: string, tone: string}> */
    private static function setupProblems(): array
    {
        return [
            '/no such table:\s*["`]?([^\s"`;]+)/i' => self::outcome(
                'setup_problem',
                'The test database is missing a table',
                'The tests could not finish because the test database does not have a table the page needs.',
                'Ask a developer to add or run the required test migrations, then run the tests again.',
                'amber',
            ),
            '/Table\s+["`]?([^"`\s]+)["`]?\s+doesn\'t exist/i' => self::outcome(
                'setup_problem',
                'The test database is missing a table',
                'The tests could not finish because the test database does not have a table the page needs.',
                'Ask a developer to add or run the required test migrations, then run the tests again.',
                'amber',
            ),
            '/relation\s+["\']([^"\']+)["\']\s+does not exist/i' => self::outcome(
                'setup_problem',
                'The test database is missing a table',
                'The tests could not finish because the test database does not have a table the page needs.',
                'Ask a developer to add or run the required test migrations, then run the tests again.',
                'amber',
            ),
            '/no such column:\s*["`]?([^\s"`;]+)/i' => self::outcome(
                'setup_problem',
                'The test database is out of date',
                'The tests need a database column that is not present in the test database.',
                'Ask a developer to run the latest application and module migrations for the test environment.',
                'amber',
            ),
            '/could not find driver/i' => self::outcome(
                'setup_problem',
                'A database driver is missing',
                'PHP does not have the driver needed to connect to the test database.',
                'Ask a developer to install the PHP database extension used by the test environment, then rerun.',
                'amber',
            ),
            '/SQLSTATE\[HY000\] \[2002\]|connection refused/i' => self::outcome(
                'setup_problem',
                'The test database is unreachable',
                'The test process could not connect to its database.',
                'Ask a developer to start the test database or correct its test-environment connection settings.',
                'amber',
            ),
            '/SQLSTATE\[HY000\] \[1045\]|access denied for user/i' => self::outcome(
                'setup_problem',
                'The test database rejected its credentials',
                'The test process could not log in to the database.',
                'Ask a developer to check the database credentials used by the test environment.',
                'amber',
            ),
            '/No application encryption key has been specified/i' => self::outcome(
                'setup_problem',
                'Test environment configuration is incomplete',
                'The test process needs an application encryption key before it can continue.',
                'Ask a developer to configure APP_KEY for the test environment.',
                'amber',
            ),
        ];
    }

    private static function value(array|object $value, string $key): mixed
    {
        return is_array($value) ? ($value[$key] ?? null) : ($value->{$key} ?? null);
    }

    /**
     * @return array{code: string, title: string, summary: string, next_step: string, tone: string}
     */
    private static function outcome(string $code, string $title, string $summary, string $nextStep, string $tone): array
    {
        return [
            'code' => $code,
            'title' => $title,
            'summary' => $summary,
            'next_step' => $nextStep,
            'tone' => $tone,
        ];
    }
}
