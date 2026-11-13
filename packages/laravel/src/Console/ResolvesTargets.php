<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Console;

use TestPilot\Laravel\Models\Target;

trait ResolvesTargets
{
    /** Accepts a target id, a full key ("App\Services\X::y") or a short name ("X::y"). */
    private function findTarget(string $needle): ?Target
    {
        $needle = trim($needle);

        return Target::query()->active()->whereKey($needle)->first()
            ?? Target::query()->active()->where('key_hash', hash('sha256', ltrim($needle, '\\')))->first()
            ?? Target::query()->active()->get()->first(fn (Target $t): bool => strcasecmp($t->shortName(), $needle) === 0);
    }
}
