<?php

declare(strict_types=1);

namespace TestPilot\Configuration;

enum Module: string
{
    case Unit = 'unit';
    case Fuzz = 'fuzz';
    case Validation = 'validation';
    case RateLimit = 'ratelimit';
    case Feature = 'feature';
    case Ai = 'ai';

    /** Map an attribute short name to the module it controls. */
    public static function fromAttribute(string $attribute): ?self
    {
        return match ($attribute) {
            'UnitTest' => self::Unit,
            'Fuzz' => self::Fuzz,
            'Validation' => self::Validation,
            'RateLimit' => self::RateLimit,
            'FeatureTest' => self::Feature,
            'AI' => self::Ai,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Unit',
            self::Fuzz => 'Fuzz',
            self::Validation => 'Validation',
            self::RateLimit => 'Rate limit',
            self::Feature => 'HTTP feature',
            self::Ai => 'AI',
        };
    }
}
