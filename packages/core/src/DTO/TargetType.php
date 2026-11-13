<?php

declare(strict_types=1);

namespace TestPilot\DTO;

enum TargetType: string
{
    case Method = 'method';
    case Function = 'function';
}
