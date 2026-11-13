<?php

declare(strict_types=1);

namespace TestPilot\Exceptions;

/** The provider's quota window is too long for an automatic retry to help. */
final class AiQuotaExceededException extends AiProviderException
{
}
