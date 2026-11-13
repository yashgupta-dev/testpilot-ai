<?php

declare(strict_types=1);

namespace TestPilot\Ai;

use TestPilot\Exceptions\InvalidAiResponseException;

/** Pulls a JSON object out of a model reply (bare JSON or inside a ```json fence). */
final class JsonExtractor
{
    /** @return array<string, mixed> */
    public static function object(string $content): array
    {
        $candidates = [];

        if (preg_match_all('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches) > 0) {
            array_push($candidates, ...$matches[1]);
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidates[] = substr($content, $start, $end - $start + 1);
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded) && ! array_is_list($decoded)) {
                return $decoded;
            }
        }

        // Models quoting PHP often write single backslashes ("\Illuminate\Support…" as \I, \S), which JSON forbids,
        // or leave a trailing comma. Repair only those two things, and only when the strict parse failed.
        foreach ($candidates as $candidate) {
            $decoded = json_decode(self::repair($candidate), true);
            if (is_array($decoded) && ! array_is_list($decoded)) {
                return $decoded;
            }
        }

        throw new InvalidAiResponseException('The AI response is not a JSON object', [json_last_error_msg()], $content);
    }

    /** Doubles backslashes that do not start a valid JSON escape and drops trailing commas before } or ]. */
    public static function repair(string $json): string
    {
        $json = (string) preg_replace_callback(
            '/\\\\(u[0-9a-fA-F]{4}|.)/s',
            static fn (array $m): string => strlen($m[1]) === 5 || strpbrk($m[1], '"\\/bfnrt') !== false && strlen($m[1]) === 1
                ? $m[0]
                : '\\\\' . $m[1],
            $json,
        );

        return (string) preg_replace('/,\s*([}\]])/', '$1', $json);
    }
}
