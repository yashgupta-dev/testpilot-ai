<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedTest extends Model
{
    use HasUlids;

    protected $table = 'testpilot_generated_tests';

    protected $guarded = [];

    protected $casts = [
        'verification' => 'array',
        'mutation_score' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public const GENERATING = 'generating';
    public const VERIFIED = 'verified';
    public const SUSPECTED_BUG = 'suspected_bug';
    public const FAILED = 'failed';
    public const UNSAFE = 'unsafe';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const STALE = 'stale';

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function awaitingReview(): bool
    {
        return in_array($this->status, [self::VERIFIED, self::SUSPECTED_BUG], true);
    }
}
