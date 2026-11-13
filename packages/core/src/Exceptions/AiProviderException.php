<?php

declare(strict_types=1);

namespace TestPilot\Exceptions;

/** The AI provider failed (network, HTTP error, timeout or bad credentials). */
class AiProviderException extends TestPilotException
{
    public static function isLongWindowQuota(string $message): bool
    {
        return preg_match('/\b(?:tokens?|requests?)\s+per\s+(?:day|week|month)\b|\bTP[DW]\b|\b(?:daily|weekly|monthly)\s+(?:token|request)\s+(?:limit|quota)\b/i', $message) === 1;
    }
}
