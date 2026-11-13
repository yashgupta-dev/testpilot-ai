<?php

declare(strict_types=1);

namespace TestPilot\Validation;

use TestPilot\Ai\AiGateway;
use TestPilot\Ai\AiRequest;
use TestPilot\Ai\JsonExtractor;
use TestPilot\Ai\PromptRegistry;
use TestPilot\Exceptions\InvalidAiResponseException;

/**
 * AI assistance for the validation module, where the deterministic engine stops:
 *
 *   1. regex / not_regex — the AI proposes a matching and a non-matching value; TestPilot checks both with
 *      preg_match. Only values that pass are used, and then they count as confirmed, like any other rule.
 *   2. custom rule objects and closures — the AI reads the rule class and proposes values. These cannot be
 *      checked locally, so they are assumptions: unselected by default, and a failure is never reported as a
 *      bug in the application.
 *   3. gap review — rules that look missing or too weak for how the controller uses the data. Findings only.
 *
 * The AI never decides what a known rule means: every case for a known rule still comes from the fixed rule → input
 * table in ValidationCaseGenerator. One corrective retry is made with the exact checks that failed.
 */
final class AiValidationAdvisor
{
    private const MAX_VALUE_LENGTH = 500;
    private const MAX_GAPS = 10;
    private const MAX_CASES = 20;
    private const MAX_RULE_SOURCE = 6_000;
    private const MAX_SOURCE = 12_000;
    private const NUMERIC_CASE_RULES = ['required', 'sometimes', 'present', 'filled', 'nullable', 'numeric', 'integer', 'min', 'max', 'between'];

    public function __construct(
        private readonly AiGateway $ai,
        private readonly PromptRegistry $prompts = new PromptRegistry(),
    ) {
    }

    /**
     * Rules the deterministic engine cannot produce values for, numbered Q1, Q2, …
     *
     * @return array<string, array{field: string, path: string, rule: string, kind: string, pattern: ?string, line: int}>
     */
    public static function questions(RulesExtraction $rules): array
    {
        $questions = [];
        $next = 1;
        foreach ($rules->fields as $field) {
            foreach ($field->rules as $rule) {
                $kind = match (true) {
                    $rule->kind === ValidationRule::KNOWN && in_array($rule->name, ['regex', 'not_regex'], true) => $rule->name,
                    $rule->kind === ValidationRule::CUSTOM => 'custom',
                    default => null,
                };
                if ($kind === null) {
                    continue;
                }
                $questions['Q' . $next++] = [
                    'field' => $field->field,
                    'path' => $field->path(),
                    'rule' => $rule->label(),
                    'kind' => $kind,
                    'pattern' => $kind === 'custom' ? null : $rule->param(0),
                    'line' => $field->line,
                ];
            }
        }

        return $questions;
    }

    /**
     * @param array<string, mixed>  $baseline     Payload the deterministic engine built.
     * @param string                $source       Source of the FormRequest (or the controller file for inline rules).
     * @param string|null           $actionSource Source of the controller action, to see how validated data is used.
     * @param array<string, string> $ruleSources  Custom rule class name => its source, when the adapter could read it.
     * @param array<string, mixed>  $meta         Routing hints (criticality, target).
     */
    public function buildRequest(RulesExtraction $rules, array $baseline, string $source, ?string $actionSource = null, array $ruleSources = [], array $meta = [], int $maxCases = 6, array $existingCases = []): AiRequest
    {
        $questions = self::questions($rules);

        return AiRequest::withContext(
            purpose: 'validation',
            system: $this->prompts->render('validation.review.system', [], 'v2'),
            context: $this->context($rules, $baseline, $source, $actionSource, $ruleSources, $existingCases),
            task: $this->prompts->render('validation.review.user', [
                'questions' => $questions === []
                    ? 'There are no open questions. Return an empty "values" list, propose eligible extra cases if any, and review gaps.'
                    : implode("\n", array_map(
                        static fn (string $id, array $q): string => "{$id} · {$q['field']} · {$q['rule']}" . ($q['pattern'] !== null ? " · pattern {$q['pattern']}" : ''),
                        array_keys($questions),
                        $questions,
                    )),
                'max_cases' => (string) min(self::MAX_CASES, max(0, $maxCases)),
            ], 'v2'),
            maxTokens: 2048,
            promptVersion: 'validation@v2',
            meta: [...$meta, 'json' => true, 'validation_max_cases' => min(self::MAX_CASES, max(0, $maxCases))],
        );
    }

    /** Sends the request, checks the answer and retries once with the exact problems. */
    public function advise(RulesExtraction $rules, AiRequest $request, ?string $targetKey = null, bool $fresh = false): AiValidationAdvice
    {
        $questions = self::questions($rules);
        $fields = [];
        foreach ($rules->fields as $f) {
            $fields[$f->path()] = $f;
        }

        $request = $request->fresh($fresh);
        $response = $this->ai->send($request, $targetKey);
        $calls = 1;
        $maxCases = min(self::MAX_CASES, max(0, (int) ($request->meta['validation_max_cases'] ?? 6)));
        $checked = $this->check($response->content, $questions, $fields, $maxCases);

        if ($checked['retry'] !== []) {
            $retry = $request->fresh()->withAppendedTurn(
                $response->content,
                "TestPilot checked your answer and found problems:\n- " . implode("\n- ", $checked['retry'])
                . "\nReturn the complete corrected JSON object only.",
            );
            $response = $this->ai->send($retry, $targetKey);
            $calls++;
            $checked = $this->check($response->content, $questions, $fields, $maxCases);
        }

        if ($checked['schema'] !== []) {
            throw new InvalidAiResponseException('The AI validation review is invalid after one retry', $checked['schema'], $response->content);
        }

        return new AiValidationAdvice(
            values: $checked['values'],
            rejected: $checked['rejected'],
            gaps: $checked['gaps'],
            notes: $checked['notes'],
            model: $response->model,
            promptVersion: 'ai-review@v2',
            calls: $calls,
            cases: $checked['cases'],
        );
    }

    /**
     * Merges checked AI values into a plan from ValidationCaseGenerator::generate(): fixes the baseline payload,
     * turns the matching "needs a human" cases into real ones and adds an "accepts" case for each verified pattern.
     *
     * @param array{baseline: array<string, mixed>, baseline_issues: list<string>, scenarios: list<array<string, mixed>>} $generated
     *
     * @return array{baseline: array<string, mixed>, baseline_issues: list<string>, scenarios: list<array<string, mixed>>}
     */
    public static function apply(array $generated, RulesExtraction $rules, AiValidationAdvice $advice): array
    {
        $questions = self::questions($rules);
        $fields = [];
        foreach ($rules->fields as $f) {
            $fields[$f->path()] = $f;
        }

        $baseline = $generated['baseline'];
        $issues = $generated['baseline_issues'];
        $assumed = false;

        // Assumptions first, so a verified value for the same field wins.
        $ordered = $advice->values;
        uasort($ordered, static fn (array $a, array $b): int => $a['verified'] <=> $b['verified']);

        foreach ($ordered as $value) {
            $q = $questions[$value['question']] ?? null;
            if ($q === null) {
                continue;
            }
            ValidationCaseGenerator::setPath($baseline, $q['path'], $value['valid']);
            if (($fields[$q['path']] ?? null)?->has('confirmed')) {
                ValidationCaseGenerator::setPath($baseline, $q['path'] . '_confirmation', $value['valid']);
            }

            $resolved = $q['kind'] === 'custom'
                ? "'{$q['field']}' has a custom rule that may reject generated values."
                : "'{$q['field']}' must match a regular expression.";
            $before = count($issues);
            $issues = array_values(array_filter($issues, static fn (string $i): bool => $i !== $resolved));
            $assumed = $assumed || (! $value['verified'] && count($issues) < $before);
        }

        $scenarios = [];
        $extra = [];
        $next = count($generated['scenarios']) + 1;
        $knownCases = [];
        foreach ($generated['scenarios'] as $scenario) {
            $meta = (array) ($scenario['meta'] ?? []);
            $knownCases[self::caseKey((string) ($meta['field'] ?? ''), (string) ($meta['action'] ?? ''), $meta['value'] ?? null, (string) ($meta['expect'] ?? ''))] = true;
        }

        foreach ($generated['scenarios'] as $scenario) {
            $meta = (array) ($scenario['meta'] ?? []);
            $match = null;
            if (($meta['todo'] ?? null) !== null && ($meta['field'] ?? null) !== null) {
                foreach ($advice->values as $value) {
                    $q = $questions[$value['question']] ?? null;
                    if ($q !== null && $q['path'] === $meta['field'] && $q['rule'] === $meta['rule']) {
                        $match = [$q, $value];
                        break;
                    }
                }
            }

            if ($match === null) {
                $scenarios[] = $scenario;
                continue;
            }

            [$q, $value] = $match;
            $field = $fields[$q['path']] ?? null;
            $scenarios[] = self::scenario($scenario['id'], $q, $value, $rules, $field, 'error');

            if ($value['verified']) {
                $extra[] = self::scenario('S' . $next++, $q, $value, $rules, $field, 'no_error');
            }
        }

        // S1 (the fully valid payload) can run once nothing blocks the baseline any more.
        foreach ($scenarios as $i => $scenario) {
            if (($scenario['meta']['field'] ?? null) !== null || ($scenario['meta']['rule'] ?? '') !== 'all rules') {
                continue;
            }
            $todo = $issues === [] ? null : 'A fully valid payload could not be built: ' . implode(' ', $issues);
            $scenarios[$i] = [
                ...$scenario,
                'description' => $todo ?? ($assumed ? 'The payload uses values the AI proposed for custom rules (assumption).' : ''),
                'basis' => $todo === null && ! $assumed ? 'confirmed' : 'assumption',
                'confidence' => $todo === null ? ($assumed ? 0.7 : 1.0) : 0.0,
                'selected' => $todo === null,
                'meta' => [...$scenario['meta'], 'todo' => $todo],
            ];
        }

        foreach ($advice->cases as $case) {
            $field = $fields[$case['field']] ?? null;
            if ($field === null) {
                continue;
            }
            $expect = $case['expect'];
            $value = $case['value'] ?? null;
            $key = self::caseKey($field->path(), $case['action'], $value, $expect);
            if (isset($knownCases[$key])) {
                continue;
            }
            $knownCases[$key] = true;
            $input = $case['action'] === 'remove' ? '(field missing)' : ValidationCaseGenerator::describeValue($value);
            $expectation = $expect === 'error'
                ? self::errorExpectation($rules, $field->path())
                : "no error on {$field->path()}";
            $number = $next++;
            $extra[] = [
                'id' => 'S' . $number,
                'name' => "{$field->field} · AI-proposed numeric input — " . ($expect === 'error' ? 'rejects' : 'accepts') . ' ' . ($case['reason'] ?: 'additional input'),
                'type' => $expect === 'error' ? 'negative' : 'edge',
                'module' => 'validation',
                'description' => '',
                'inputs' => $input,
                'expected' => $expectation,
                'basis' => 'confirmed',
                'basis_source' => sprintf("%s line %d: '%s' => %s — AI-proposed input, independently checked against the supported numeric rules", $rules->label(), $field->line, $field->field, $field->rulesText()),
                'confidence' => 1.0,
                'priority' => 'medium',
                'selected' => true,
                'meta' => [
                    'field' => $field->path(),
                    'rule' => 'AI-proposed numeric input',
                    'action' => $case['action'],
                    'value' => $value,
                    'target_field' => $field->path(),
                    'expect' => $expect,
                    'todo' => null,
                    'also_set' => [],
                    'ai' => true,
                    'verified' => true,
                ],
            ];
        }

        return ['baseline' => $baseline, 'baseline_issues' => $issues, 'scenarios' => [...$scenarios, ...$extra]];
    }

    private static function caseKey(string $field, string $action, mixed $value, string $expect): string
    {
        return hash('sha256', json_encode([$field, $action, $value, $expect], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{field: string, path: string, rule: string, kind: string, pattern: ?string, line: int} $q
     * @param array{question: string, valid: mixed, invalid: mixed, verified: bool, confidence: float, reason: string} $value
     *
     * @return array<string, mixed>
     */
    private static function scenario(string $id, array $q, array $value, RulesExtraction $rules, ?FieldRules $field, string $expect): array
    {
        $verified = $value['verified'];
        $input = $expect === 'error' ? $value['invalid'] : $value['valid'];
        $what = match (true) {
            $expect === 'no_error' => 'accepts ' . ($q['kind'] === 'not_regex' ? 'a value that avoids the pattern' : ($q['kind'] === 'regex' ? 'a value matching the pattern' : 'a value the rule allows')),
            $q['kind'] === 'regex' => 'rejects a value that does not match the pattern',
            $q['kind'] === 'not_regex' => 'rejects a value that matches the forbidden pattern',
            default => 'rejects a value the rule forbids',
        };

        $alsoSet = [];
        if ($field?->has('confirmed')) {
            $alsoSet[$q['path'] . '_confirmation'] = $input;
        }

        return [
            'id' => $id,
            'name' => "{$q['field']} · {$q['rule']} — {$what} (AI-proposed)",
            'type' => $expect === 'error' ? 'negative' : 'edge',
            'module' => 'validation',
            'description' => $verified
                ? ''
                : 'AI reading of the rule, not verifiable locally: ' . $value['reason'] . ' Select it only if this matches what the rule is meant to do.',
            'inputs' => ValidationCaseGenerator::describeValue($input),
            'expected' => $expect === 'error' ? self::errorExpectation($rules, $q['path']) : "no error on {$q['path']}",
            'basis' => $verified ? 'confirmed' : 'assumption',
            'basis_source' => $verified
                ? sprintf("%s line %d: '%s' => %s — value proposed by AI, checked locally with preg_match", $rules->label(), $q['line'], $q['field'], $q['rule'])
                : sprintf("%s line %d: '%s' => %s — AI: %s", $rules->label(), $q['line'], $q['field'], $q['rule'], $value['reason']),
            'confidence' => $verified ? 1.0 : $value['confidence'],
            'priority' => $expect === 'error' ? 'high' : 'medium',
            'selected' => $verified,
            'meta' => [
                'field' => $q['path'],
                'rule' => $q['rule'],
                'action' => 'set',
                'value' => $input,
                'target_field' => $q['path'],
                'expect' => $expect,
                'todo' => null,
                'also_set' => $alsoSet,
                'ai' => true,
                'verified' => $verified,
            ],
        ];
    }

    private static function errorExpectation(RulesExtraction $rules, string $field): string
    {
        if (($rules->inline['error_mode'] ?? null) === 'redirect_errors') {
            return "302 with a session error on {$field}";
        }

        return (int) ($rules->inline['error_status'] ?? 422) . " with an error on {$field}";
    }

    /**
     * @param array<string, array<string, mixed>> $questions
     * @param array<string, FieldRules>           $fields
     *
     * @return array{values: array<string, array<string, mixed>>, cases: list<array{field: string, action: string, value: mixed, expect: string, reason: string}>, rejected: list<array{question: ?string, reason: string}>, gaps: list<array<string, string>>, notes: string, retry: list<string>, schema: list<string>}
     */
    private function check(string $content, array $questions, array $fields, int $maxCases): array
    {
        $out = ['values' => [], 'cases' => [], 'rejected' => [], 'gaps' => [], 'notes' => '', 'retry' => [], 'schema' => []];

        try {
            $data = JsonExtractor::object($content);
        } catch (InvalidAiResponseException) {
            $out['schema'][] = $out['retry'][] = 'the reply was not a single JSON object';

            return $out;
        }

        foreach (['values', 'gaps', 'cases'] as $key) {
            if (isset($data[$key]) && (! is_array($data[$key]) || ! array_is_list($data[$key]))) {
                $out['schema'][] = $out['retry'][] = "\"{$key}\" must be a JSON array";
            }
        }
        if ($out['schema'] !== []) {
            return $out;
        }
        $out['notes'] = mb_substr(is_string($data['notes'] ?? null) ? $data['notes'] : '', 0, 1000);

        foreach ((array) ($data['values'] ?? []) as $i => $value) {
            $id = is_array($value) && is_string($value['question'] ?? null) ? $value['question'] : null;
            $problem = $this->problem($value, $id, $questions, $fields, $out['values']);
            if ($problem !== null) {
                $out['rejected'][] = ['question' => $id, 'reason' => $problem['reason']];
                if ($problem['retry']) {
                    $out['retry'][] = ($id ?? 'values[' . $i . ']') . ': ' . $problem['reason'];
                }
                continue;
            }

            $q = $questions[$id];
            $out['values'][$id] = [
                'question' => $id,
                'field' => $q['field'],
                'rule' => $q['rule'],
                'valid' => $value['valid'],
                'invalid' => $value['invalid'],
                'verified' => $q['kind'] !== 'custom',
                'confidence' => $q['kind'] === 'custom' ? max(0.0, min(0.8, (float) ($value['confidence'] ?? 0.5))) : 1.0,
                'reason' => mb_substr(is_string($value['reason'] ?? null) ? $value['reason'] : '', 0, self::MAX_VALUE_LENGTH),
            ];
        }

        foreach (array_slice((array) ($data['cases'] ?? []), 0, $maxCases) as $index => $case) {
            $problem = $this->checkExtraCase($case, $fields);
            if ($problem !== null) {
                $out['rejected'][] = ['question' => null, 'reason' => $problem];
                continue;
            }

            $field = $fields[$case['field']];
            $expect = $this->evaluateNumericCase($field, $case['action'], $case['value'] ?? null);
            if ($expect === null) {
                $out['rejected'][] = ['question' => null, 'reason' => "cases[{$index}] uses rules TestPilot cannot independently verify for {$field->field}"];
                continue;
            }
            $out['cases'][] = [
                'field' => $field->path(),
                'action' => $case['action'],
                'value' => $case['action'] === 'remove' ? null : $case['value'],
                'expect' => $expect ? 'no_error' : 'error',
                'reason' => mb_substr(trim($case['reason']), 0, 100),
            ];
        }

        foreach (array_slice((array) ($data['gaps'] ?? []), 0, self::MAX_GAPS) as $gap) {
            if (! is_array($gap) || ! is_string($gap['issue'] ?? null) || trim($gap['issue']) === '') {
                continue;
            }
            $severity = is_string($gap['severity'] ?? null) ? strtolower($gap['severity']) : 'medium';
            $text = static fn (string $key): string => mb_substr(is_string($gap[$key] ?? null) ? trim($gap[$key]) : '', 0, self::MAX_VALUE_LENGTH);
            $out['gaps'][] = [
                'field' => $text('field'),
                'severity' => in_array($severity, ['high', 'medium', 'low'], true) ? $severity : 'medium',
                'issue' => $text('issue'),
                'suggestion' => $text('suggestion'),
                'basis_source' => $text('basis_source'),
            ];
        }

        // A missing answer for a regex question is worth one more try; custom rules may legitimately stay open.
        foreach ($questions as $id => $q) {
            if ($q['kind'] !== 'custom' && ! isset($out['values'][$id]) && ! in_array($id, array_column($out['rejected'], 'question'), true)) {
                $out['retry'][] = "{$id}: no values were given for {$q['field']} · {$q['rule']}";
            }
        }

        return $out;
    }

    /**
     * @param array<string, FieldRules> $fields
     */
    private function checkExtraCase(mixed $case, array $fields): ?string
    {
        if (! is_array($case) || ! is_string($case['field'] ?? null) || ! isset($fields[$case['field']])) {
            return 'an extra case must name a field from the rules';
        }
        if (! in_array($case['action'] ?? null, ['set', 'remove'], true)) {
            return 'an extra case action must be "set" or "remove"';
        }
        if (($case['action'] ?? null) === 'set' && (! array_key_exists('value', $case) || (! is_scalar($case['value']) && $case['value'] !== null))) {
            return 'a set case value must be a scalar or null';
        }
        if (is_string($case['value'] ?? null) && strlen($case['value']) > self::MAX_VALUE_LENGTH) {
            return 'an extra case value exceeds the maximum length';
        }
        if (! is_string($case['reason'] ?? null) || trim($case['reason']) === '') {
            return 'an extra case needs a short scenario description';
        }

        return null;
    }

    /** True means the payload passes; null means the field's rules are outside the safe local evaluator. */
    private function evaluateNumericCase(FieldRules $field, string $action, mixed $value): ?bool
    {
        if ($field->type() !== 'numeric' && $field->type() !== 'integer') {
            return null;
        }
        if (array_filter($field->rules, static fn (ValidationRule $rule): bool => $rule->kind !== ValidationRule::KNOWN || ! in_array($rule->name, self::NUMERIC_CASE_RULES, true)) !== []) {
            return null;
        }
        if (! $field->has('numeric') && ! $field->has('integer')) {
            return null;
        }

        if ($action === 'remove') {
            if ($field->has('sometimes')) {
                return true;
            }

            return ! ($field->has('required') || $field->has('present'));
        }
        if ($value === null || $value === '') {
            if ($field->has('required') || $field->has('filled')) {
                return false;
            }
            if ($value === null && $field->has('nullable')) {
                return true;
            }

            return false;
        }

        if ($field->has('numeric') && ! is_numeric($value)) {
            return false;
        }
        if ($field->has('integer') && filter_var($value, FILTER_VALIDATE_INT) === false) {
            return false;
        }
        if (! is_numeric($value)) {
            return true;
        }

        $number = (float) $value;
        foreach ($field->rules as $rule) {
            if ($rule->name === 'min' && $number < (float) ($rule->param(0) ?? 0)) {
                return false;
            }
            if ($rule->name === 'max' && $number > (float) ($rule->param(0) ?? 0)) {
                return false;
            }
            if ($rule->name === 'between' && ($number < (float) ($rule->param(0) ?? 0) || $number > (float) ($rule->param(1) ?? 0))) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> field path => rule expression */
    private static function extraCaseFields(RulesExtraction $rules): array
    {
        $fields = [];
        foreach ($rules->fields as $field) {
            if (! in_array($field->type(), ['numeric', 'integer'], true)) {
                continue;
            }
            if (! $field->has('numeric') && ! $field->has('integer')) {
                continue;
            }
            if (array_filter($field->rules, static fn (ValidationRule $rule): bool => $rule->kind !== ValidationRule::KNOWN || ! in_array($rule->name, self::NUMERIC_CASE_RULES, true)) !== []) {
                continue;
            }
            $fields[$field->path()] = $field->rulesText();
        }

        return $fields;
    }

    /**
     * @param array<string, array<string, mixed>> $questions
     * @param array<string, FieldRules>           $fields
     * @param array<string, mixed>                $accepted
     *
     * @return array{reason: string, retry: bool}|null
     */
    private function problem(mixed $value, ?string $id, array $questions, array $fields, array $accepted): ?array
    {
        $fail = static fn (string $reason, bool $retry = true): array => ['reason' => $reason, 'retry' => $retry];

        if (! is_array($value) || $id === null) {
            return $fail('each entry needs a "question" id');
        }
        if (! isset($questions[$id])) {
            return $fail("\"{$id}\" is not one of the questions asked");
        }
        if (isset($accepted[$id])) {
            return $fail('answered twice; only the first answer is kept', false);
        }
        foreach (['valid', 'invalid'] as $key) {
            if (! array_key_exists($key, $value) || ! (is_scalar($value[$key]) || $value[$key] === null)) {
                return $fail("\"{$key}\" must be a string, number, boolean or null");
            }
            if (is_string($value[$key]) && strlen($value[$key]) > self::MAX_VALUE_LENGTH) {
                return $fail("\"{$key}\" is longer than " . self::MAX_VALUE_LENGTH . ' characters');
            }
        }
        if ($value['valid'] === $value['invalid']) {
            return $fail('"valid" and "invalid" are the same value');
        }

        $q = $questions[$id];
        if ($q['pattern'] !== null) {
            $valid = @preg_match($q['pattern'], (string) $value['valid']);
            $invalid = @preg_match($q['pattern'], (string) $value['invalid']);
            if ($valid === false || $invalid === false) {
                return $fail("the pattern {$q['pattern']} cannot be compiled by PHP, so no value can be checked", false);
            }
            $mustMatch = $q['kind'] === 'regex';
            if (($valid === 1) !== $mustMatch) {
                return $fail(sprintf('valid value %s %s %s (checked with preg_match)', json_encode($value['valid']), $mustMatch ? 'does not match' : 'matches', $q['pattern']));
            }
            if (($invalid === 1) === $mustMatch) {
                return $fail(sprintf('invalid value %s %s %s (checked with preg_match)', json_encode($value['invalid']), $mustMatch ? 'matches' : 'does not match', $q['pattern']));
            }
        }

        $known = $this->breaksKnownRules($fields[$q['path']] ?? null, $value['valid']);

        return $known === null ? null : $fail(sprintf('valid value %s breaks %s on the same field', json_encode($value['valid']), $known));
    }

    /** Cheap local checks of a proposed valid value against the field's other known rules. */
    private function breaksKnownRules(?FieldRules $field, mixed $value): ?string
    {
        if ($field === null || $value === null) {
            return null;
        }

        if (($in = $field->get('in')) !== null && $in->params !== [] && ! in_array((string) $value, $in->params, true)) {
            return $in->label();
        }
        if ($field->type() !== 'string' || ! is_string($value)) {
            return null;
        }

        $length = mb_strlen($value);
        foreach (['min' => 0, 'between' => 0, 'size' => 0] as $name => $index) {
            $min = $field->get($name)?->param($index);
            if ($min !== null && is_numeric($min) && $length < (int) $min) {
                return $field->get($name)->label();
            }
        }
        foreach (['max' => 0, 'between' => 1, 'size' => 0] as $name => $index) {
            $max = $field->get($name)?->param($index);
            if ($max !== null && is_numeric($max) && $length > (int) $max) {
                return $field->get($name)->label();
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>  $baseline
     * @param array<string, string> $ruleSources
     */
    private function context(RulesExtraction $rules, array $baseline, string $source, ?string $actionSource, array $ruleSources, array $existingCases): string
    {
        $cut = static fn (string $code, int $max): string => strlen($code) > $max ? substr($code, 0, $max) . "\n// … truncated by TestPilot" : $code;

        $lines = array_map(
            static fn (FieldRules $f): string => "- {$f->field}: {$f->rulesText()} (line {$f->line})",
            $rules->fields,
        );

        $parts = [];
        $parts[] = "## Validation rules read from {$rules->label()}\n" . implode("\n", $lines)
            . ($rules->complete ? '' : "\n(Part of the rules could not be read statically.)");
        $parts[] = "## Valid payload TestPilot built from the known rules\n```json\n"
            . json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n```";
        $extraCaseFields = self::extraCaseFields($rules);
        $parts[] = "## Eligible fields for additional cases\n"
            . implode("\n", array_map(static fn (string $field, string $rulesText): string => "- {$field}: {$rulesText}", array_keys($extraCaseFields), $extraCaseFields));
        $eligibleFields = array_fill_keys(array_keys($extraCaseFields), true);
        $existing = [];
        foreach ($existingCases as $scenario) {
            $meta = (array) ($scenario['meta'] ?? []);
            $field = (string) ($meta['field'] ?? '');
            if (! isset($eligibleFields[$field]) || ! isset($meta['action'], $meta['expect'])) {
                continue;
            }
            $value = $meta['action'] === 'remove' ? '(missing)' : json_encode($meta['value'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $existing[] = "- {$field}: {$meta['action']} {$value} → {$meta['expect']}";
        }
        if ($existing !== []) {
            $parts[] = "## Existing cases for eligible numeric fields (do not repeat)\n" . implode("\n", $existing);
        }
        $parts[] = "## Source of {$rules->file}\n```php\n" . $cut($source, self::MAX_SOURCE) . "\n```";

        if ($actionSource !== null && trim($actionSource) !== '') {
            $parts[] = "## Controller action (how the validated data is used)\n```php\n" . $cut($actionSource, self::MAX_SOURCE) . "\n```";
        }
        foreach ($ruleSources as $class => $code) {
            $parts[] = "## Custom rule {$class}\n```php\n" . $cut($code, self::MAX_RULE_SOURCE) . "\n```";
        }

        return implode("\n\n", $parts);
    }
}
