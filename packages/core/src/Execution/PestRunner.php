<?php

declare(strict_types=1);

namespace TestPilot\Execution;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use TestPilot\Security\EnvironmentGuard;
use TestPilot\Security\PathGuard;

/**
 * Runs the project's own Pest binary in a separate process.
 *
 *  - Commands are built as argument arrays (no shell, no string interpolation).
 *  - The child gets a minimal, allow-listed environment with APP_ENV=testing.
 *  - Every run has a timeout. Generated code never runs inside the web/dashboard process.
 */
final class PestRunner
{
    /**
     * @param list<string>          $binary     e.g. ['vendor/bin/pest'] (relative to the root).
     * @param array<string, string> $extraEnv   Additional environment variables for the child process.
     * @param list<string>          $passEnv    Names of parent environment variables that may be inherited.
     */
    public function __construct(
        private readonly PathGuard $guard,
        private readonly EnvironmentGuard $environment,
        private readonly array $binary = ['vendor/bin/pest'],
        private readonly int $timeoutSeconds = 300,
        private readonly array $extraEnv = [],
        private readonly array $passEnv = ['PATH', 'HOME', 'TMPDIR', 'TEMP', 'LANG', 'COMPOSER_HOME', 'XDEBUG_MODE'],
        private readonly string $phpBinary = PHP_BINARY,
    ) {
    }

    /**
     * @param list<string> $paths       Test files or directories, relative to the root.
     * @param list<string> $extraArgs   Additional Pest arguments (e.g. ['--order-by=random']).
     */
    public function run(array $paths, array $extraArgs = []): RunResult
    {
        $this->environment->assertTestingAllowed();

        $files = array_map(fn (string $p): string => $this->guard->relative($this->guard->resolveInside($p)), $paths);
        $junit = tempnam(sys_get_temp_dir(), 'testpilot-junit-');
        if ($junit === false) {
            throw new \RuntimeException('Could not create a temporary file for the JUnit report.');
        }

        $command = [...$this->binaryCommand(), ...$files, '--colors=never', '--log-junit', $junit, ...$extraArgs];
        $process = new Process($command, $this->guard->root(), $this->childEnvironment(), null, $this->timeoutSeconds);

        $started = hrtime(true);
        $timedOut = false;
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        }
        $duration = (hrtime(true) - $started) / 1_000_000;

        $xml = is_file($junit) ? (string) file_get_contents($junit) : '';
        @unlink($junit);

        return new RunResult(
            exitCode: $timedOut ? 124 : (int) $process->getExitCode(),
            cases: (new JunitReportParser())->parse($xml),
            output: mb_substr($process->getOutput() . $process->getErrorOutput(), -20000),
            durationMs: round($duration, 2),
            command: $this->display($command, $junit),
            timedOut: $timedOut,
        );
    }

    /** Runs Pest's mutation testing for one test file. */
    public function mutate(string $path): MutationReport
    {
        $this->environment->assertTestingAllowed();
        $file = $this->guard->relative($this->guard->resolveInside($path));

        $process = new Process(
            [...$this->binaryCommand(), $file, '--mutate', '--colors=never', '--no-progress'],
            $this->guard->root(),
            $this->childEnvironment(),
            null,
            $this->timeoutSeconds * 3,
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new MutationReport(null, null, null, null, [], 'Mutation testing timed out.', false);
        }

        return MutationReport::fromOutput($process->getOutput() . $process->getErrorOutput());
    }

    /** `php -l` on a file. Returns null when valid, or the error message. */
    public function lint(string $path): ?string
    {
        $absolute = $this->guard->resolveInside($path);
        $process = new Process([$this->phpBinary, '-l', $absolute], $this->guard->root(), $this->childEnvironment(), null, 30);
        $process->run();

        return $process->isSuccessful() ? null : trim($process->getOutput() . $process->getErrorOutput());
    }

    /** @return list<string> */
    private function binaryCommand(): array
    {
        $first = $this->binary[0] ?? 'vendor/bin/pest';

        // Run PHP scripts through the PHP binary so the executable bit and shebang do not matter.
        if (! str_ends_with($first, '.bat') && is_file($this->guard->root() . '/' . $first)) {
            return [$this->phpBinary, $first, ...array_slice($this->binary, 1)];
        }

        return $this->binary;
    }

    /** @return array<string, string|false> */
    private function childEnvironment(): array
    {
        $env = [];

        // Symfony Process inherits the parent environment; explicitly unset everything not allow-listed.
        foreach (array_keys(getenv()) as $name) {
            $env[$name] = false;
        }
        foreach ($this->passEnv as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $env[$name] = $value;
            }
        }

        return [
            ...$env,
            'APP_ENV' => 'testing',
            'TESTPILOT_CHILD' => '1',
            ...$this->extraEnv,
        ];
    }

    /** @param list<string> $command */
    private function display(array $command, string $junit): string
    {
        $shown = array_map(static fn (string $part): string => $part === $junit ? '<junit.xml>' : $part, $command);
        if (($shown[0] ?? null) === $this->phpBinary) {
            $shown[0] = 'php';
        }

        return implode(' ', array_map(static fn (string $p): string => preg_match('/\s/', $p) === 1 ? escapeshellarg($p) : $p, $shown));
    }
}
