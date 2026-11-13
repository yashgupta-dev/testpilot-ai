<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AiRequest extends Model
{
    use HasUlids;

    protected $table = 'testpilot_ai_requests';

    protected $guarded = [];

    protected $casts = ['response_cache_hit' => 'boolean', 'batch' => 'boolean'];

    public const UPDATED_AT = null;
}
