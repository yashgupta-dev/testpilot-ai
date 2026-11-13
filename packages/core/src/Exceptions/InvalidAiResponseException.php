<?php

declare(strict_types=1);

namespace TestPilot\Exceptions;

/** The AI returned output that does not match the expected schema. */
class InvalidAiResponseException extends TestPilotException
{
    /** @param list<string> $errors */
    public function __construct(string $message, public readonly array $errors = [], public readonly string $raw = '')
    {
        parent::__construct($message . ($errors === [] ? '' : ': ' . implode('; ', $errors)));
    }
}
