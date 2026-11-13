<?php

declare(strict_types=1);

namespace TestPilot\Validation;

/**
 * Turns FormRequest rules into concrete, deterministic test cases using a fixed rule → input table.
 * No AI is involved, so every expectation is "confirmed" (it comes straight from rules()).
 *
 * Each case changes ONE field of an otherwise valid payload and asserts only on that field's error,
 * so a problem with another field cannot make the case pass or fail by accident.
 */
final class ValidationCaseGenerator
{
    private const FAR_FUTURE = '2099-12-31';
    private const FAR_PAST = '2000-01-01';

    /** @var list<array<string, mixed>> */
    private array $scenarios = [];

    /** @var list<string> */
    private array $baselineIssues = [];

    private int $next = 1;

    /**
     * @return array{baseline: array<string, mixed>, baseline_issues: list<string>, scenarios: list<array<string, mixed>>}
     */
    public function generate(RulesExtraction $rules, int $additionalCases = 0, int $seed = 42): array
    {
        $this->scenarios = [];
        $this->baselineIssues = [];
        $this->next = 1;

        // Parents first, so "items" is built before "items.*.price" fills in its first element.
        $ordered = $rules->fields;
        usort($ordered, static fn (FieldRules $a, FieldRules $b): int => substr_count($a->field, '.') <=> substr_count($b->field, '.'));

        $baseline = [];
        foreach ($ordered as $field) {
            if ($this->omit($field)) {
                continue;
            }
            $value = $this->validValue($field);
            self::setPath($baseline, $field->path(), $value);
            if ($field->has('confirmed')) {
                self::setPath($baseline, $field->path() . '_confirmation', $value);
            }
        }

        if (! $rules->complete) {
            $this->baselineIssues[] = 'Part of the rules could not be read statically.';
        }

        $source = static fn (FieldRules $f, string $rule): string => sprintf("%s line %d: '%s' => %s", $rules->label(), $f->line, $f->field, $rule);

        // S1: the whole valid payload passes validation.
        $this->add(
            name: 'Accepts a fully valid payload',
            field: null,
            rule: 'all rules',
            action: 'none',
            value: null,
            expect: 'no_errors',
            basis: $this->baselineIssues === [] ? 'confirmed' : 'assumption',
            basisSource: 'Valid value derived from every rule in ' . $rules->label(),
            todo: $this->baselineIssues === [] ? null : 'A fully valid payload could not be built: ' . implode(' ', $this->baselineIssues),
            priority: 'high',
        );

        foreach ($rules->fields as $field) {
            foreach ($field->rules as $rule) {
                foreach ($this->casesFor($field, $rule, $baseline) as $case) {
                    $this->add(
                        name: $case['name'],
                        field: $field,
                        rule: $rule->label(),
                        action: $case['action'],
                        value: $case['value'] ?? null,
                        expect: $case['expect'],
                        basis: 'confirmed',
                        basisSource: $source($field, $rule->label()),
                        todo: $case['todo'] ?? null,
                        priority: $case['priority'] ?? 'medium',
                        extraField: $case['extra_field'] ?? null,
                    );
                }
            }
        }

        foreach ($this->additionalNumericCases($rules->fields, $baseline, min(20, max(0, $additionalCases)), $seed) as $case) {
            $this->add(
                name: $case['name'],
                field: $case['field'],
                rule: 'additional numeric input',
                action: 'set',
                value: $case['value'],
                expect: 'no_error',
                basis: 'confirmed',
                basisSource: 'Seeded value within the statically readable numeric bounds',
                todo: null,
                priority: 'medium',
            );
        }

        return ['baseline' => $baseline, 'baseline_issues' => $this->baselineIssues, 'scenarios' => $this->scenarios];
    }

    /**
     * @param list<FieldRules> $fields
     * @param array<string, mixed> $baseline
     * @return list<array{field: FieldRules, value: int|float, name: string}>
     */
    private function additionalNumericCases(array $fields, array $baseline, int $limit, int $seed): array
    {
        if ($limit === 0) {
            return [];
        }

        $eligible = array_values(array_filter($fields, fn (FieldRules $field): bool => $this->supportsAdditionalNumericCases($field)));
        if ($eligible === []) {
            return [];
        }

        $extra = [];
        $basePerField = intdiv($limit, count($eligible));
        $remainder = $limit % count($eligible);
        foreach ($eligible as $fieldIndex => $field) {
            $fieldLimit = $basePerField + ($fieldIndex < $remainder ? 1 : 0);
            if ($fieldLimit === 0) {
                continue;
            }
            $lower = null;
            $upper = null;
            foreach ($field->rules as $rule) {
                if ($rule->name === 'min') {
                    $lower = max($lower ?? -INF, (float) ($rule->param(0) ?? 0));
                } elseif ($rule->name === 'max') {
                    $upper = min($upper ?? INF, (float) ($rule->param(0) ?? 0));
                } elseif ($rule->name === 'between' && count($rule->params) >= 2) {
                    $lower = max($lower ?? -INF, (float) $rule->params[0]);
                    $upper = min($upper ?? INF, (float) $rule->params[1]);
                }
            }

            if ($lower === null && $upper === null) {
                continue;
            }

            $isInteger = $field->type() === 'integer';
            $minimum = $lower === null ? null : ($isInteger ? (int) ceil($lower) : $lower);
            $maximum = $upper === null ? null : ($isInteger ? (int) floor($upper) : $upper);
            if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
                continue;
            }

            $baselineValue = $this->valueAtPath($baseline, $field->path());
            $usedValues = [];
            foreach ($this->scenarios as $scenario) {
                $meta = (array) ($scenario['meta'] ?? []);
                if (($meta['field'] ?? null) === $field->path() && ($meta['action'] ?? null) === 'set' && ($meta['expect'] ?? null) === 'no_error') {
                    $usedValues[] = $meta['value'] ?? null;
                }
            }

            $values = [];
            for ($index = 0; count($values) < $fieldLimit && $index < 100; $index++) {
                $hash = hexdec(substr(hash('sha256', $seed . "\0" . $field->field . "\0" . $index), 0, 7));
                if ($isInteger) {
                    if ($minimum !== null && $maximum !== null) {
                        $span = $maximum - $minimum + 1;
                        if ($span <= count($values) + 1) {
                            break;
                        }
                        $candidate = $minimum + ($hash % $span);
                    } elseif ($minimum !== null) {
                        $candidate = $minimum + 1 + ($hash % 100);
                    } else {
                        $candidate = $maximum - 1 - ($hash % 100);
                    }
                } elseif ($minimum !== null && $maximum !== null) {
                    if ($maximum <= $minimum) {
                        break;
                    }
                    $ratio = (($hash % 900) + 50) / 1000;
                    $candidate = $minimum + (($maximum - $minimum) * $ratio);
                } elseif ($minimum !== null) {
                    $candidate = $minimum + 1 + (($hash % 900) / 1000);
                } else {
                    $candidate = $maximum - 1 - (($hash % 900) / 1000);
                }

                if (! $isInteger) {
                    $candidate = round($candidate, 6);
                }
                if (($minimum !== null && $candidate < $minimum) || ($maximum !== null && $candidate > $maximum)) {
                    continue;
                }
                if ($candidate == $baselineValue || in_array($candidate, $usedValues) || in_array($candidate, $values)) {
                    continue;
                }

                $values[] = $candidate;
                $extra[] = [
                    'field' => $field,
                    'value' => $candidate,
                    'name' => 'accepts an additional valid numeric value ' . (count($values)),
                ];
            }
        }

        return $extra;
    }

    private function supportsAdditionalNumericCases(FieldRules $field): bool
    {
        if (! in_array($field->type(), ['integer', 'numeric'], true) || $field->has('decimal') || $field->has('size') || ! ($field->has('min') || $field->has('max') || $field->has('between'))) {
            return false;
        }

        $supported = ['required', 'present', 'filled', 'numeric', 'integer', 'min', 'max', 'between', 'nullable'];

        return array_filter($field->rules, static fn (ValidationRule $rule): bool => ! in_array($rule->name, $supported, true) || $rule->kind !== ValidationRule::KNOWN) === [];
    }

    /** @param array<string, mixed> $values */
    private function valueAtPath(array $values, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            $key = ctype_digit($segment) ? (int) $segment : $segment;
            if (! is_array($values) || ! array_key_exists($key, $values)) {
                return null;
            }
            $values = $values[$key];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $baseline
     *
     * @return list<array<string, mixed>>
     */
    private function casesFor(FieldRules $f, ValidationRule $rule, array $baseline): array
    {
        $type = $f->type();
        $reject = static fn (string $what, mixed $value, string $priority = 'high'): array => ['name' => "rejects {$what}", 'action' => 'set', 'value' => $value, 'expect' => 'error', 'priority' => $priority];
        $accept = static fn (string $what, mixed $value): array => ['name' => "accepts {$what}", 'action' => 'set', 'value' => $value, 'expect' => 'no_error', 'priority' => 'medium'];
        $todo = static fn (string $what, string $why): array => ['name' => $what, 'action' => 'none', 'expect' => 'error', 'todo' => $why, 'priority' => 'low'];
        $n = (int) ($rule->param(0) ?? 0);

        if ($rule->kind === ValidationRule::CUSTOM) {
            return [$todo('custom rule', 'Custom rule objects and closures need a human-written case, or AI-proposed values (testpilot.validation.ai).')];
        }
        if ($rule->kind === ValidationRule::DYNAMIC) {
            return [$todo('dynamic rule', ($rule->note ?? 'Computed at runtime') . ' — not analysed.')];
        }

        return match ($rule->name) {
            'required' => [['name' => 'rejects a missing value', 'action' => 'remove', 'expect' => 'error', 'priority' => 'high']],
            'present' => [['name' => 'rejects a missing field', 'action' => 'remove', 'expect' => 'error', 'priority' => 'high']],
            'filled' => [$reject('an empty value', '')],
            'string' => [$reject('a non-string value', 12345)],
            'integer' => [$reject('a non-integer value', 'abc'), $reject('a decimal number', 1.5, 'medium')],
            'numeric', 'decimal' => [$reject('a non-numeric value', 'abc')],
            'boolean' => [$reject('a non-boolean value', 'maybe')],
            'array', 'list' => [$reject('a non-array value', 'not-an-array')],
            'email' => [$reject('an invalid email', 'abc@')],
            'url', 'active_url' => [$reject('an invalid URL', 'not a url')],
            'uuid' => [$reject('an invalid UUID', 'not-a-uuid')],
            'ulid' => [$reject('an invalid ULID', 'not-a-ulid')],
            'ip', 'ipv4' => [$reject('an invalid IP address', '999.999.1.1')],
            'ipv6' => [$reject('an invalid IPv6 address', '12345::xyz')],
            'json' => [$reject('invalid JSON', '{invalid')],
            'date', 'date_format' => [$reject('an invalid date', 'not-a-date')],
            'alpha' => [$reject('non-letters', 'abc123')],
            'alpha_num' => [$reject('symbols', 'abc-123!')],
            'alpha_dash' => [$reject('spaces and symbols', 'abc 123!')],
            'lowercase' => [$reject('uppercase text', 'UPPER')],
            'uppercase' => [$reject('lowercase text', 'lower')],
            'digits' => $n > 0 ? [$reject("{$n} + 1 digits", str_repeat('1', $n + 1)), $accept("exactly {$n} digits", str_repeat('1', $n))] : [],
            'in' => $rule->params === [] ? [] : [$reject('a value outside the allowed list', 'tp-not-allowed-value')],
            'not_in' => $rule->params === [] ? [] : [$reject("the forbidden value \"{$rule->params[0]}\"", $this->cast($rule->params[0], $type))],
            'enum' => [$reject('a value that is not an enum case', 'tp-invalid-enum-value')],
            'starts_with' => [$reject('a value with the wrong prefix', 'zzz-value')],
            'ends_with' => [$reject('a value with the wrong suffix', 'value-zzz')],
            'confirmed' => [['name' => 'rejects a mismatched confirmation', 'action' => 'set', 'value' => 'tp-mismatch-value', 'expect' => 'error', 'priority' => 'high', 'extra_field' => $f->path() . '_confirmation']],
            'accepted' => [$reject('a declined value', false)],
            'declined' => [$reject('an accepted value', true)],
            'prohibited' => [$reject('any value', 'not-allowed')],
            'nullable' => [$accept('null', null)],
            'after', 'after_or_equal' => $this->isRelativeDate($rule) ? [$reject('a date in the past', self::FAR_PAST)] : [],
            'before', 'before_or_equal' => $this->isRelativeDate($rule) ? [$reject('a date in the future', self::FAR_FUTURE)] : [],
            'exists' => [$reject('a value that does not exist in ' . ($rule->param(0) ?? 'the table'), in_array($type, ['integer', 'numeric'], true) ? 999999999 : 'tp-non-existent-999999')],
            'unique' => [$todo('rejects a duplicate value', 'Needs an existing ' . ($rule->param(0) ?? 'database') . ' record; add one with a model factory and approve this case manually.')],
            'regex', 'not_regex' => [$todo('pattern check', 'TestPilot cannot guarantee a value that breaks an arbitrary regular expression.')],
            'password' => $n > 1 ? [$reject("a password shorter than {$n} characters", str_repeat('a', $n - 1))] : [],
            'min' => $this->sizeCases($f, $type, $n, 'min'),
            'max' => $this->sizeCases($f, $type, $n, 'max'),
            'size' => $this->sizeCases($f, $type, $n, 'size'),
            'between' => [...$this->sizeCases($f, $type, $n, 'min'), ...$this->sizeCases($f, $type, (int) ($rule->param(1) ?? 0), 'max')],
            'file', 'image', 'mimes', 'mimetypes', 'dimensions' => [$todo('file upload rule', 'File uploads are not generated yet; JSON requests cannot carry files.')],
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    private function sizeCases(FieldRules $f, string $type, int $n, string $kind): array
    {
        $value = fn (int $size): mixed => match ($type) {
            'integer' => $size,
            'numeric' => $size,
            'array' => array_fill(0, max(0, $size), 'x'),
            'file', 'boolean', 'date' => null,
            default => $this->stringOfLength($f, $size),
        };
        $unit = match ($type) {
            'integer', 'numeric' => '',
            'array' => ' items',
            default => ' characters',
        };

        if (in_array($type, ['file', 'boolean', 'date'], true)) {
            return [];
        }

        if ($type === 'string' && ($value($n) === null || ($kind !== 'max' && $n > 0 && $value($n - 1) === null) || ($kind !== 'min' && $value($n + 1) === null))) {
            $why = $f->has('email') && $n >= 254
                ? "Laravel's email rule already rejects addresses over 254 characters, so a {$kind}:{$n} boundary can never be reached."
                : 'This field has a fixed format (UUID, IP, URL, date, pattern…), so a value of this exact length cannot be built.';

            return [['name' => "{$kind} boundary", 'action' => 'none', 'expect' => 'error', 'todo' => $why, 'priority' => 'low']];
        }

        return match ($kind) {
            'min' => array_values(array_filter([
                $n > 0 || in_array($type, ['integer', 'numeric'], true) ? ['name' => "rejects " . ($n - 1) . "{$unit} (min {$n})", 'action' => 'set', 'value' => $value($n - 1), 'expect' => 'error', 'priority' => 'high'] : null,
                ['name' => "accepts exactly {$n}{$unit} (min boundary)", 'action' => 'set', 'value' => $value($n), 'expect' => 'no_error', 'priority' => 'medium'],
            ])),
            'max' => [
                ['name' => 'rejects ' . ($n + 1) . "{$unit} (max {$n})", 'action' => 'set', 'value' => $value($n + 1), 'expect' => 'error', 'priority' => 'high'],
                ['name' => "accepts exactly {$n}{$unit} (max boundary)", 'action' => 'set', 'value' => $value($n), 'expect' => 'no_error', 'priority' => 'medium'],
            ],
            default => [
                ['name' => 'rejects ' . ($n + 1) . "{$unit} (size {$n})", 'action' => 'set', 'value' => $value($n + 1), 'expect' => 'error', 'priority' => 'high'],
            ],
        };
    }

    private function validValue(FieldRules $f): mixed
    {
        $type = $f->type();
        $rule = static fn (string $name): ?ValidationRule => $f->get($name);

        foreach ($f->rules as $r) {
            if ($r->kind === ValidationRule::DYNAMIC) {
                $this->baselineIssues[] = "'{$f->field}' has a rule computed at runtime.";
            }
            if ($r->kind === ValidationRule::CUSTOM) {
                $this->baselineIssues[] = "'{$f->field}' has a custom rule that may reject generated values.";
            }
        }

        if (($in = $rule('in')) !== null && $in->params !== []) {
            return $this->cast($in->params[0], $type);
        }
        if ($rule('enum') !== null) {
            $this->baselineIssues[] = "'{$f->field}' must be an enum case, which TestPilot cannot read statically.";

            return 'tp-enum-value';
        }
        if ($rule('exists') !== null) {
            $this->baselineIssues[] = "'{$f->field}' must exist in " . ($rule('exists')->param(0) ?? 'the database') . '; create it with a factory.';
        }
        if ($rule('regex') !== null) {
            $this->baselineIssues[] = "'{$f->field}' must match a regular expression.";
        }
        if (in_array($type, ['file'], true)) {
            $this->baselineIssues[] = "'{$f->field}' is a file upload.";

            return null;
        }

        $min = $rule('min')?->param(0) ?? $rule('between')?->param(0) ?? $rule('size')?->param(0);
        $max = $rule('max')?->param(0) ?? $rule('between')?->param(1) ?? $rule('size')?->param(0);

        return match ($type) {
            'integer' => (int) ($min ?? ($max !== null ? min(1, (int) $max) : 1)) + 0,
            'numeric' => (float) ($min ?? ($max !== null ? min(1, (float) $max) : 1)),
            'boolean' => $rule('declined') !== null ? false : true,
            'array' => $this->arrayValue($f, $min),
            'date' => $this->dateValue($f),
            default => $this->stringValue($f, $min !== null ? (int) $min : null, $max !== null ? (int) $max : null),
        };
    }

    /** @return list<mixed> */
    private function arrayValue(FieldRules $f, ?string $min): array
    {
        return array_fill(0, max(1, (int) ($min ?? 1)), 'value');
    }

    private function dateValue(FieldRules $f): string
    {
        foreach (['after', 'after_or_equal'] as $name) {
            if (($r = $f->get($name)) !== null && $this->isRelativeDate($r)) {
                return self::FAR_FUTURE;
            }
        }
        foreach (['before', 'before_or_equal'] as $name) {
            if (($r = $f->get($name)) !== null && $this->isRelativeDate($r)) {
                return self::FAR_PAST;
            }
        }

        $format = $f->get('date_format')?->param(0);

        return $format !== null ? (string) date($format, (int) strtotime('2026-06-15 10:30:00')) : '2026-06-15';
    }

    private function stringValue(FieldRules $f, ?int $min, ?int $max): string
    {
        $value = match (true) {
            $f->has('email') => 'tp.user@example.com',
            $f->has('url') || $f->has('active_url') => 'https://example.com',
            $f->has('uuid') => '3f1c2b7e-8a4d-4c1e-9f2a-1b2c3d4e5f60',
            $f->has('ulid') => '01J9Z3K5M8N2P4Q6R8S0T2V4W6',
            $f->has('ipv6') => '2001:db8::1',
            $f->has('ip') || $f->has('ipv4') => '192.168.1.10',
            $f->has('json') => '{"key":"value"}',
            $f->has('timezone') => 'Asia/Kolkata',
            $f->has('mac_address') => '00:1A:2B:3C:4D:5E',
            $f->has('digits') => str_repeat('1', max(1, (int) $f->get('digits')->param(0))),
            $f->has('alpha') => 'Abcdef',
            $f->has('alpha_num') => 'Abc123',
            $f->has('alpha_dash') => 'abc-123',
            $f->has('lowercase') => 'value',
            $f->has('uppercase') => 'VALUE',
            $f->has('password') => 'Tp-Str0ng!Passw0rd',
            default => 'TestPilot value',
        };

        if (($prefix = $f->get('starts_with')?->param(0)) !== null) {
            $value = $prefix . 'value';
        }
        if (($suffix = $f->get('ends_with')?->param(0)) !== null) {
            $value .= $suffix;
        }
        if ($min !== null && strlen($value) < $min) {
            $value .= str_repeat('a', $min - strlen($value));
        }
        if ($max !== null && strlen($value) > $max && ! $f->has('email') && ! $f->has('url')) {
            $value = substr($value, 0, max(0, $max));
        }

        return $value;
    }

    /**
     * A string of exactly $length characters that still satisfies the field's other format rules,
     * so a min/max case fails (or passes) because of its length only. Null when that is impossible.
     */
    private function stringOfLength(FieldRules $f, int $length): ?string
    {
        if ($length < 0) {
            return null;
        }

        if ($f->has('email')) {
            // Laravel's email rule rejects anything over 254 characters before any other check.
            return $length > 254 ? null : self::emailOfLength($length);
        }

        foreach (['uuid', 'ulid', 'ip', 'ipv4', 'ipv6', 'json', 'mac_address', 'timezone', 'date', 'url', 'active_url', 'regex'] as $fixed) {
            if ($f->has($fixed)) {
                return null;
            }
        }

        $prefix = (string) ($f->get('starts_with')?->param(0) ?? '');
        $suffix = (string) ($f->get('ends_with')?->param(0) ?? '');
        $fill = $f->has('uppercase') ? 'A' : ($f->has('digits') ? '1' : 'a');
        $middle = $length - strlen($prefix) - strlen($suffix);

        return $middle < 0 ? null : $prefix . str_repeat($fill, $middle) . $suffix;
    }

    /** A syntactically valid address of an exact length: "tp@aaa…aaa.aaa….com" (labels ≤ 61 characters). */
    public static function emailOfLength(int $length): ?string
    {
        $prefix = 'tp@';
        $suffix = '.com';
        $budget = $length - strlen($prefix) - strlen($suffix);
        if ($budget < 1) {
            return null;
        }

        $labels = [];
        while ($budget > 0) {
            if ($labels !== []) {
                $budget--; // the dot between labels
            }
            $take = min(61, $budget); // egulias counts the preceding dot in the label (limit 63)
            if ($budget - $take === 1 && $take > 1) {
                $take--; // never leave a single character that cannot fit after a dot
            }
            if ($take < 1) {
                return null;
            }
            $labels[] = str_repeat('a', $take);
            $budget -= $take;
        }

        return $prefix . implode('.', $labels) . $suffix;
    }

    private function omit(FieldRules $f): bool
    {
        return $f->has('prohibited') || $f->has('missing') || $f->has('exclude');
    }

    private function isRelativeDate(ValidationRule $rule): bool
    {
        return in_array(strtolower((string) $rule->param(0)), ['today', 'now', 'tomorrow', 'yesterday'], true);
    }

    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'integer' => is_numeric($value) ? (int) $value : $value,
            'numeric' => is_numeric($value) ? (float) $value : $value,
            default => $value,
        };
    }

    private function add(
        string $name,
        ?FieldRules $field,
        string $rule,
        string $action,
        mixed $value,
        string $expect,
        string $basis,
        string $basisSource,
        ?string $todo,
        string $priority,
        ?string $extraField = null,
    ): void {
        $id = 'S' . $this->next++;

        // Changing a "confirmed" field must change its confirmation too, unless the case is about the mismatch.
        $alsoSet = [];
        if ($field !== null && $action === 'set' && $field->has('confirmed') && $extraField === null) {
            $alsoSet[$field->path() . '_confirmation'] = $value;
        }
        $label = $field === null ? $name : "{$field->field} · {$rule} — {$name}";

        $this->scenarios[] = [
            'id' => $id,
            'name' => $label,
            'type' => $expect === 'error' ? 'negative' : ($field === null ? 'positive' : 'edge'),
            'module' => 'validation',
            'description' => $todo ?? '',
            'inputs' => match ($action) {
                'remove' => '(field missing)',
                'none' => $field === null ? 'valid payload' : '—',
                default => self::describeValue($value),
            },
            'expected' => match ($expect) {
                'error' => '422 with an error on ' . ($field?->path() ?? 'the field'),
                'no_error' => 'no error on ' . ($field?->path() ?? 'the field'),
                default => 'no validation errors',
            },
            'basis' => $basis,
            'basis_source' => $basisSource,
            'confidence' => $todo === null ? 1.0 : 0.0,
            'priority' => $priority,
            'selected' => $todo === null,
            'meta' => [
                'field' => $field?->path(),
                'rule' => $rule,
                'action' => $action,
                'value' => $value,
                'target_field' => $extraField ?? $field?->path(),
                'expect' => $expect,
                'todo' => $todo,
                'also_set' => $alsoSet,
            ],
        ];
    }

    public static function describeValue(mixed $value): string
    {
        if (is_string($value) && strlen($value) > 24) {
            return strlen($value) . ' × "' . $value[0] . '"';
        }
        if (is_array($value)) {
            return 'array of ' . count($value);
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sets a value in a nested array using dot notation ("items.0.price"); framework-free data_set().
     *
     * @param array<string, mixed> $array
     */
    public static function setPath(array &$array, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $current = &$array;
        foreach ($segments as $i => $segment) {
            $key = ctype_digit($segment) ? (int) $segment : $segment;
            if ($i === count($segments) - 1) {
                $current[$key] = $value;

                return;
            }
            if (! isset($current[$key]) || ! is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
    }
}
