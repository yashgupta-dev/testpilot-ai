<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Target extends Model
{
    use HasUlids;

    protected $table = 'testpilot_targets';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'modules' => 'array',
        'config' => 'array',
        'data' => 'array',
        'risk_reasons' => 'array',
        'route' => 'array',
        'last_run_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public const NOT_PLANNED = 'not_planned';
    public const PLANNING = 'planning';
    public const PLAN_READY = 'plan_ready';
    public const GENERATING = 'generating';
    public const AWAITING_APPROVAL = 'awaiting_approval';
    public const APPROVED = 'approved';
    public const STALE = 'stale';
    public const NEEDS_ATTENTION = 'needs_attention';
    public const SKIPPED = 'skipped';

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class)->latest();
    }

    public function latestPlan(): HasOne
    {
        return $this->hasOne(Plan::class)->latestOfMany();
    }

    public function generatedTests(): HasMany
    {
        return $this->hasMany(GeneratedTest::class)->latest();
    }

    public function latestTest(): HasOne
    {
        return $this->hasOne(GeneratedTest::class)->latestOfMany();
    }

    public function results(): HasMany
    {
        return $this->hasMany(RunResult::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function shortName(): string
    {
        if ($this->type === 'function') {
            return ($this->function ?? '') . '()';
        }

        return class_basename((string) $this->class) . '::' . $this->method;
    }
}
