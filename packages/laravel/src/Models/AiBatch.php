<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiBatch extends Model
{
    use HasUlids;

    public const IN_PROGRESS = 'in_progress';
    public const ENDED = 'ended';
    public const FAILED = 'failed';

    protected $table = 'testpilot_ai_batches';

    protected $guarded = [];

    protected $casts = ['counts' => 'array', 'ended_at' => 'datetime'];

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class, 'ai_batch_id');
    }
}
