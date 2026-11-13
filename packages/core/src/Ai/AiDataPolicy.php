<?php

declare(strict_types=1);

namespace TestPilot\Ai;

use TestPilot\Exceptions\AiDisabledException;

/**
 * Controls whether and where source code may be sent.
 *
 *  - off:   no AI calls at all.
 *  - local: only providers that run on localhost / the private network (e.g. Ollama).
 *  - cloud: cloud providers allowed; secrets are always redacted first.
 */
enum AiDataPolicy: string
{
    case Off = 'off';
    case Local = 'local';
    case Cloud = 'cloud';

    public function assertAllows(AiProviderInterface $provider): void
    {
        if ($this === self::Off) {
            throw new AiDisabledException('AI is disabled by the data policy (TESTPILOT_AI_POLICY=off).');
        }

        if ($this === self::Local && ! $provider->isLocal()) {
            throw new AiDisabledException(
                "The data policy only allows local AI providers, but \"{$provider->name()}\" is a cloud provider.",
            );
        }
    }
}
