<?php

declare(strict_types=1);

namespace TestPilot\Risk;

use TestPilot\DTO\TestTarget;

/**
 * Explainable risk score (0–100) used to sort targets. Every point added comes with a reason
 * the dashboard can show ("Why test this").
 */
final class RiskScorer
{
    /** Words that suggest money, security or data-integrity logic. */
    private const SENSITIVE = [
        'money' => ['payment', 'refund', 'invoice', 'price', 'total', 'tax', 'discount', 'charge', 'billing', 'subscription', 'amount', 'currency'],
        'security' => ['auth', 'login', 'password', 'token', 'permission', 'role', 'secret', 'otp', 'verify'],
        'data' => ['delete', 'destroy', 'import', 'export', 'migrate', 'sync'],
    ];

    public function score(TestTarget $target, RiskSignals $signals): RiskAssessment
    {
        $score = 0;
        $reasons = [];

        if ($target->complexity > 1) {
            $points = min(30, ($target->complexity - 1) * 3);
            $score += $points;
            $level = $target->complexity >= 10 ? 'high' : ($target->complexity >= 5 ? 'medium' : 'low');
            $reasons[] = [
                'label' => "Complexity {$target->complexity}",
                'detail' => "Cyclomatic complexity is {$level} ({$target->complexity} paths through the code).",
                'points' => $points,
            ];
        }

        if (! $signals->hasApprovedTests) {
            $score += 25;
            $reasons[] = ['label' => 'No approved tests', 'detail' => 'No TestPilot test covers this target yet.', 'points' => 25];
        }

        if ($signals->recentChanges > 0) {
            $points = min(15, $signals->recentChanges * 5);
            $score += $points;
            $reasons[] = [
                'label' => "Changed {$signals->recentChanges}× recently",
                'detail' => "Modified {$signals->recentChanges} time(s) in the last {$signals->changeWindowDays} days (git history).",
                'points' => $points,
            ];
        }

        $haystack = strtolower($target->key . ' ' . $target->signature);
        foreach (self::SENSITIVE as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    $label = match ($category) {
                        'money' => 'Handles money',
                        'security' => 'Security-sensitive',
                        default => 'Changes stored data',
                    };
                    $score += 20;
                    $reasons[] = ['label' => $label, 'detail' => "Name or signature mentions \"{$word}\".", 'points' => 20];
                    break 2;
                }
            }
        }

        if ($signals->isPublicRoute) {
            $score += 10;
            $reasons[] = ['label' => 'Public HTTP route', 'detail' => 'Reachable by clients over HTTP.', 'points' => 10];
        }

        if ($signals->lastRunFailed) {
            $score += 10;
            $reasons[] = ['label' => 'Failing', 'detail' => 'The last test run for this target failed.', 'points' => 10];
        }

        $score = max(0, min(100, $score));

        return new RiskAssessment($score, RiskAssessment::levelFor($score), $reasons);
    }
}
