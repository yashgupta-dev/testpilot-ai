<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plan extends Model
{
    use HasUlids;

    protected $table = 'testpilot_plans';

    protected $guarded = [];

    protected $casts = [
        'scenarios' => 'array',
        'context' => 'array',
        'approved_at' => 'datetime',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }
}
