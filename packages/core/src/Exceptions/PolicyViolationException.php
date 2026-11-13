<?php

declare(strict_types=1);

namespace TestPilot\Exceptions;

/** A security policy or execution limit was violated. Policies always win over annotations and AI output. */
class PolicyViolationException extends TestPilotException
{
}
