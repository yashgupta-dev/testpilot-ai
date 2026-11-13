<?php

declare(strict_types=1);

namespace TestPilot\Ai;

/**
 * Removes secrets from text before it is sent to an AI provider.
 * Conservative by design: false positives only hide a value, false negatives would leak one.
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    /** @var array<string, string> label => regex */
    private const PATTERNS = [
        'private_key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
        'anthropic_key' => '/sk-ant-[A-Za-z0-9_\-]{20,}/',
        'openai_key' => '/sk-(?:proj-)?[A-Za-z0-9_\-]{20,}/',
        'aws_access_key' => '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
        'github_token' => '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/',
        'slack_token' => '/\bxox[abposr]-[A-Za-z0-9\-]{10,}\b/',
        'stripe_key' => '/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{16,}\b/',
        'google_api_key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
        'jwt' => '/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\b/',
        'bearer' => '/(Bearer\s+)[A-Za-z0-9._\-]{16,}/i',
        'connection_string' => '#\b([a-z][a-z0-9+\-.]*://[^:\s/]+:)[^@\s/]+(@)#i',
    ];

    /** Assignments such as  'password' => 'x',  $apiKey = "x",  DB_PASSWORD=x */
    private const ASSIGNMENT = '/((?:["\']?)(?:[A-Za-z0-9_]*(?:password|passwd|secret|api[_-]?key|token|private[_-]?key|access[_-]?key|client[_-]?secret)[A-Za-z0-9_]*)(?:["\']?)\s*(?:=>|=|:)\s*)(["\'])(?!\[REDACTED\])([^"\']{4,})\2/i';

    private const ENV_LINE = '/^(\s*[A-Z][A-Z0-9_]*(?:PASSWORD|SECRET|KEY|TOKEN)[A-Z0-9_]*\s*=\s*)(.+)$/m';

    /** @var list<string> */
    private array $found = [];

    public function redact(string $text): string
    {
        $this->found = [];

        foreach (self::PATTERNS as $label => $pattern) {
            $text = (string) preg_replace_callback($pattern, function (array $m) use ($label): string {
                $this->found[] = $label;

                return match ($label) {
                    'bearer' => $m[1] . self::MASK,
                    'connection_string' => $m[1] . self::MASK . $m[2],
                    default => self::MASK,
                };
            }, $text);
        }

        $text = (string) preg_replace_callback(self::ASSIGNMENT, function (array $m): string {
            $this->found[] = 'assignment';

            return $m[1] . $m[2] . self::MASK . $m[2];
        }, $text);

        return (string) preg_replace_callback(self::ENV_LINE, function (array $m): string {
            $this->found[] = 'env';

            return $m[1] . self::MASK;
        }, $text);
    }

    /** @return list<string> Kinds of secrets removed by the last redact() call. */
    public function lastFindings(): array
    {
        return $this->found;
    }
}
