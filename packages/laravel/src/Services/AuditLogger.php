<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Database\Eloquent\Model;
use TestPilot\Laravel\Models\AuditLog;

/** Append-only record of who did what: scans, plan approvals, test approvals, rejections, runs. */
final class AuditLogger
{
    /** @param array<string, mixed> $context */
    public function log(string $action, ?Model $subject = null, array $context = [], ?string $actor = null): void
    {
        $request = app()->runningInConsole() ? null : request();

        AuditLog::query()->create([
            'actor' => $actor ?? self::currentActor(),
            'action' => $action,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey(),
            'context' => $context,
            'ip' => $request?->ip(),
        ]);
    }

    public static function currentActor(): string
    {
        $user = auth()->user();

        if ($user !== null) {
            return (string) ($user->email ?? $user->name ?? $user->getAuthIdentifier());
        }

        return app()->runningInConsole() ? 'cli:' . (get_current_user() ?: 'unknown') : 'guest';
    }
}
