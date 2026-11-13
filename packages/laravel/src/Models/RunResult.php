<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunResult extends Model
{
    use HasUlids;

    protected $table = 'testpilot_run_results';

    protected $guarded = [];

    protected $casts = [
        'time_ms' => 'float',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }
}
