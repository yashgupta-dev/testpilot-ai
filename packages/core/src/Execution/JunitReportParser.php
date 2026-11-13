<?php

declare(strict_types=1);

namespace TestPilot\Execution;

use DOMDocument;
use DOMElement;

/** Parses the JUnit XML written by `pest --log-junit`. */
final class JunitReportParser
{
    /** @return list<TestCaseResult> */
    public function parse(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET blocks network access; entity substitution stays disabled (no LIBXML_NOENT).
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $results = [];
        foreach ($document->getElementsByTagName('testcase') as $case) {
            /** @var DOMElement $case */
            $status = 'passed';
            $message = null;
            $details = null;

            foreach (['failure' => 'failed', 'error' => 'error', 'skipped' => 'skipped'] as $tag => $mapped) {
                $node = $case->getElementsByTagName($tag)->item(0);
                if ($node instanceof DOMElement) {
                    $status = $mapped;
                    $message = $node->getAttribute('message') ?: ($node->getAttribute('type') ?: null);
                    $details = trim($node->textContent) ?: null;
                    break;
                }
            }

            $results[] = new TestCaseResult(
                name: $this->cleanName($case->getAttribute('name')),
                class: $case->getAttribute('class') ?: $case->getAttribute('classname'),
                file: $case->getAttribute('file') ?: null,
                status: $status,
                timeMs: round(((float) $case->getAttribute('time')) * 1000, 2),
                assertions: (int) $case->getAttribute('assertions'),
                message: $message,
                details: $details,
            );
        }

        return $results;
    }

    private function cleanName(string $name): string
    {
        // Pest prefixes "__pest_evaluable_" and replaces spaces with underscores in some versions.
        $name = (string) preg_replace('/^__pest_evaluable_/', '', $name);

        return str_contains($name, ' ') ? $name : str_replace('_', ' ', $name);
    }
}
