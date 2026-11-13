<?php

declare(strict_types=1);

use TestPilot\Ai\Redactor;
use TestPilot\Exceptions\PolicyViolationException;
use TestPilot\Security\CodeSafetyChecker;
use TestPilot\Security\EnvironmentGuard;
use TestPilot\Security\PathGuard;

describe('PathGuard', function () {
    it('resolves paths inside the root', function () {
        $guard = new PathGuard(fixtures());

        expect($guard->relative($guard->resolveInside('app/Services/../Services/InvoiceService.php')))
            ->toBe('app/Services/InvoiceService.php');
    });

    it('blocks traversal outside the root', function (string $path) {
        (new PathGuard(fixtures()))->resolveInside($path);
    })->with(['../../../etc/passwd', '/etc/passwd', 'app/../../..'])->throws(PolicyViolationException::class);

    it('only allows writes inside allowed directories', function () {
        $guard = new PathGuard(temporaryDirectory());

        expect($guard->resolveWritable('storage/testpilot/pending/A.php', ['storage/testpilot/pending']))->toEndWith('/storage/testpilot/pending/A.php');

        $guard->resolveWritable('app/Models/User.php', ['storage/testpilot/pending']);
    })->throws(PolicyViolationException::class, 'is not allowed');
});

describe('EnvironmentGuard', function () {
    it('refuses to execute tests in production', function () {
        (new EnvironmentGuard('production'))->assertTestingAllowed();
    })->throws(PolicyViolationException::class);

    it('allows local and testing environments', function () {
        expect((new EnvironmentGuard('local'))->isAllowed())->toBeTrue()
            ->and((new EnvironmentGuard('PROD', ['prod']))->isAllowed())->toBeFalse();
    });
});

describe('Redactor', function () {
    it('removes secrets before anything is sent to an AI provider', function () {
        $source = <<<'PHP'
        $client = new Client(['api_key' => 'sk-ant-api03-abcdefghijklmnopqrstuvwxyz0123']);
        $password = "hunter2-secret";
        DB_PASSWORD=supersecret
        $dsn = 'mysql://app:p4ssw0rd@db.internal/shop';
        $headers = ['Authorization' => 'Bearer abcdefghijklmnopqrstuvwxyz123456'];
        $aws = 'AKIAABCDEFGHIJKLMNOP';
        $total = $price * $quantity; // ordinary code stays
        PHP;

        $redacted = (new Redactor())->redact($source);

        expect($redacted)
            ->not->toContain('sk-ant-api03')
            ->not->toContain('hunter2')
            ->not->toContain('supersecret')
            ->not->toContain('p4ssw0rd')
            ->not->toContain('abcdefghijklmnopqrstuvwxyz123456')
            ->not->toContain('AKIAABCDEFGHIJKLMNOP')
            ->toContain('$total = $price * $quantity;')
            ->toContain('mysql://app:[REDACTED]@db.internal/shop');
    });
});

describe('CodeSafetyChecker', function () {
    it('accepts an ordinary Pest test', function () {
        $code = <<<'PHP'
        <?php
        use Demo\Calculator;
        use Illuminate\Support\Facades\Http;

        it('[S1] divides', function () {
            Http::fake();
            $mock = Mockery::mock(Calculator::class);
            $data = file_get_contents(__DIR__ . '/fixture.json');
            expect((new Calculator())->divide(6, 3))->toBe(2.0);
        });
        PHP;

        expect((new CodeSafetyChecker())->check($code))->toBe([]);
    });

    it('rejects dangerous constructs', function (string $snippet, string $rule) {
        $violations = (new CodeSafetyChecker())->check("<?php\n" . $snippet);

        expect(array_column($violations, 'rule'))->toContain($rule);
    })->with([
        'shell exec' => ['shell_exec("rm -rf /");', 'process'],
        'backticks' => ['$x = `ls`;', 'process'],
        'eval' => ['eval("echo 1;");', 'eval'],
        'file write' => ['file_put_contents("/tmp/x", "y");', 'filesystem'],
        'delete' => ['unlink(__FILE__);', 'filesystem'],
        'remote read' => ['file_get_contents("https://evil.example");', 'network'],
        'curl' => ['curl_init("https://x");', 'network'],
        'env change' => ['putenv("APP_ENV=production");', 'environment'],
        'sleep' => ['sleep(10);', 'environment'],
        'exit' => ['exit(1);', 'exit'],
        'include' => ['require "/etc/passwd";', 'include'],
        'dynamic call' => ['$f = "system"; $f("id");', 'dynamic'],
        'guzzle' => ['new \GuzzleHttp\Client();', 'forbidden-class'],
        'real http' => ['\Illuminate\Support\Facades\Http::get("https://x");', 'network'],
        'writable fopen' => ['fopen(__DIR__."/x", "w");', 'filesystem'],
        'syntax' => ['function (', 'syntax'],
    ]);
});
