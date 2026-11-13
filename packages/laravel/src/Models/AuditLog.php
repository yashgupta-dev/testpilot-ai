<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUlids;

    protected $table = 'testpilot_audit_logs';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected $casts = [
        'context' => 'array',
    ];
}
