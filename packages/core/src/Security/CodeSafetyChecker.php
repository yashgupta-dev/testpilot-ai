<?php

declare(strict_types=1);

namespace TestPilot\Security;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Static safety gate for AI-generated test code. Runs BEFORE the code is ever executed.
 *
 * It is a defence-in-depth layer, not a sandbox: generated tests must still run in an isolated
 * process (and, in production setups, an isolated container) with a testing environment.
 */
final class CodeSafetyChecker
{
    private const PROCESS = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'pcntl_fork', 'posix_kill', 'dl'];
    private const FILE_WRITE = ['file_put_contents', 'fwrite', 'fputs', 'unlink', 'rmdir', 'rename', 'copy', 'mkdir', 'touch', 'chmod', 'chown', 'chgrp', 'symlink', 'link', 'move_uploaded_file', 'tmpfile'];
    private const NETWORK = ['curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'socket_create', 'socket_connect', 'mail', 'ftp_connect', 'ssh2_connect', 'gethostbyname', 'dns_get_record'];
    private const ENVIRONMENT = ['putenv', 'ini_set', 'ini_alter', 'set_time_limit', 'ignore_user_abort', 'apache_setenv', 'header', 'setcookie', 'register_shutdown_function', 'set_error_handler', 'set_exception_handler', 'sleep', 'usleep', 'time_nanosleep'];
    private const DYNAMIC = ['call_user_func', 'call_user_func_array', 'create_function', 'assert', 'extract', 'parse_str', 'unserialize'];
    private const FORBIDDEN_CLASSES = ['GuzzleHttp\\Client', 'Symfony\\Component\\HttpClient\\HttpClient', 'Symfony\\Component\\Process\\Process', 'ReflectionMethod', 'ReflectionProperty', 'SplFileObject', 'PDO', 'mysqli'];

    /**
     * @return list<array{line: int, rule: string, message: string}> Empty when the code is acceptable.
     */
    public function check(string $code): array
    {
        try {
            $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (Error $e) {
            return [['line' => $e->getStartLine(), 'rule' => 'syntax', 'message' => 'Syntax error: ' . $e->getRawMessage()]];
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $violations = [];
        $finder = new NodeFinder();

        foreach ($finder->find($ast, static fn (Node $n): bool => true) as $node) {
            $violation = $this->inspect($node);
            if ($violation !== null) {
                $violations[] = ['line' => $node->getStartLine(), 'rule' => $violation[0], 'message' => $violation[1]];
            }
        }

        return $violations;
    }

    /** @return array{0: string, 1: string}|null */
    private function inspect(Node $node): ?array
    {
        return match (true) {
            $node instanceof Expr\Eval_ => ['eval', 'eval() is not allowed'],
            $node instanceof Expr\ShellExec => ['process', 'Backtick shell execution is not allowed'],
            $node instanceof Expr\Exit_ => ['exit', 'exit()/die() is not allowed'],
            $node instanceof Expr\Include_ => ['include', 'include/require is not allowed in generated tests'],
            $node instanceof Expr\FuncCall => $this->inspectCall($node),
            $node instanceof Expr\New_ => $this->inspectNew($node),
            $node instanceof Expr\StaticCall => $this->inspectStatic($node),
            default => null,
        };
    }

    /** @return array{0: string, 1: string}|null */
    private function inspectCall(Expr\FuncCall $call): ?array
    {
        if (! $call->name instanceof Name) {
            return ['dynamic', 'Dynamic function calls are not allowed'];
        }

        $name = strtolower($call->name->getLast());

        foreach (['process' => self::PROCESS, 'filesystem' => self::FILE_WRITE, 'network' => self::NETWORK, 'environment' => self::ENVIRONMENT, 'dynamic' => self::DYNAMIC] as $rule => $list) {
            if (in_array($name, $list, true)) {
                return [$rule, "{$name}() is not allowed in generated tests"];
            }
        }

        if ($name === 'get_headers') {
            return ['network', 'get_headers() is not allowed in generated tests'];
        }

        if (in_array($name, ['file_get_contents', 'fopen', 'readfile', 'file', 'simplexml_load_file'], true)) {
            $first = $call->args[0] ?? null;
            $path = $first instanceof Node\Arg ? $first->value : null;

            if (! $this->isLocalPath($path)) {
                return ['network', "{$name}() may only read local fixture files (a literal path or one built from __DIR__)"];
            }

            if ($name === 'fopen') {
                $mode = $call->args[1] ?? null;
                $modeValue = $mode instanceof Node\Arg && $mode->value instanceof String_ ? $mode->value->value : null;
                if ($modeValue === null || strpbrk($modeValue, 'waxc+') !== false) {
                    return ['filesystem', 'fopen() may only open files read-only'];
                }
            }
        }

        return null;
    }

    /** A literal non-URL path, or a concatenation that starts from __DIR__ / __FILE__. */
    private function isLocalPath(?Node $path): bool
    {
        if ($path instanceof String_) {
            return preg_match('#^[a-z][a-z0-9+.\-]*://#i', $path->value) !== 1;
        }

        if ($path instanceof Node\Scalar\MagicConst\Dir || $path instanceof Node\Scalar\MagicConst\File) {
            return true;
        }

        if ($path instanceof Expr\BinaryOp\Concat) {
            $left = $path->left;
            while ($left instanceof Expr\BinaryOp\Concat) {
                $left = $left->left;
            }

            return $left instanceof Node\Scalar\MagicConst\Dir
                || $left instanceof Node\Scalar\MagicConst\File
                || ($left instanceof Expr\FuncCall && $left->name instanceof Name && in_array(strtolower($left->name->toString()), ['sys_get_temp_dir', 'dirname'], true));
        }

        return false;
    }

    /** @return array{0: string, 1: string}|null */
    private function inspectNew(Expr\New_ $new): ?array
    {
        if (! $new->class instanceof Name) {
            return $new->class instanceof Node\Stmt\Class_ ? null : ['dynamic', 'Dynamic class instantiation is not allowed'];
        }

        $class = ltrim($new->class->toString(), '\\');
        foreach (self::FORBIDDEN_CLASSES as $forbidden) {
            if (strcasecmp($class, $forbidden) === 0) {
                return ['forbidden-class', "new {$class} is not allowed in generated tests"];
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null */
    private function inspectStatic(Expr\StaticCall $call): ?array
    {
        if (! $call->class instanceof Name || ! $call->name instanceof Node\Identifier) {
            return ['dynamic', 'Dynamic static calls are not allowed'];
        }

        $class = ltrim($call->class->toString(), '\\');
        $method = strtolower($call->name->toString());

        if (in_array($class, ['Symfony\\Component\\Process\\Process', 'Illuminate\\Support\\Facades\\Process'], true) && $method !== 'fake') {
            return ['process', 'Running processes is not allowed in generated tests'];
        }

        if (in_array($class, ['Illuminate\\Support\\Facades\\Http'], true) && ! in_array($method, ['fake', 'preventstraypayments', 'preventstrayrequests', 'assertsent', 'assertnotsent', 'assertsentcount', 'assertnothingsent', 'sequence', 'response'], true)) {
            return ['network', 'Real HTTP calls are not allowed; use Http::fake()'];
        }

        if ($class === 'Illuminate\\Support\\Facades\\DB' && in_array($method, ['statement', 'unprepared'], true)) {
            return ['database', 'Raw DB statements are not allowed in generated tests'];
        }

        return null;
    }
}
