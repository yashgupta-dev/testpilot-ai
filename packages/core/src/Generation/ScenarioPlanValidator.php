<?php

declare(strict_types=1);

namespace TestPilot\Generation;

/**
 * Validates an AI scenario plan against schema version 1. Returns a list of human-readable errors
 * (empty when valid) so they can be fed back to the model for one corrective retry.
 */
final class ScenarioPlanValidator
{
    /**
     * @param array<string, mixed> $data
     * @param list<string>         $enabledModules
     *
     * @return list<string>
     */
    public function validate(array $data, string $targetKey, array $enabledModules, int $maxScenarios): array
    {
        $errors = [];

        if (($data['schema_version'] ?? null) !== '1') {
            $errors[] = 'schema_version must be "1"';
        }

        if (($data['target'] ?? null) !== $targetKey) {
            $errors[] = "target must be exactly \"{$targetKey}\"";
        }

        $scenarios = $data['scenarios'] ?? null;
        if (! is_array($scenarios) || ! array_is_list($scenarios) || $scenarios === []) {
            return [...$errors, 'scenarios must be a non-empty array'];
        }

        if (count($scenarios) > $maxScenarios) {
            $errors[] = "at most {$maxScenarios} scenarios are allowed, got " . count($scenarios);
        }

        $ids = [];
        foreach ($scenarios as $i => $scenario) {
            $at = 'scenarios[' . $i . ']';
            if (! is_array($scenario)) {
                $errors[] = "{$at} must be an object";
                continue;
            }

            foreach (['id', 'name', 'type', 'module', 'expected', 'basis', 'basis_source', 'priority'] as $field) {
                if (! isset($scenario[$field]) || (is_string($scenario[$field]) && trim($scenario[$field]) === '')) {
                    $errors[] = "{$at}.{$field} is required";
                }
            }

            $id = (string) ($scenario['id'] ?? '');
            if ($id !== '' && ! preg_match('/^S\d{1,3}$/', $id)) {
                $errors[] = "{$at}.id must look like S1, S2, …";
            }
            if ($id !== '' && isset($ids[$id])) {
                $errors[] = "{$at}.id \"{$id}\" is duplicated";
            }
            $ids[$id] = true;

            $this->enum($errors, $scenario, 'type', Scenario::TYPES, $at);
            $this->enum($errors, $scenario, 'basis', Scenario::BASES, $at);
            $this->enum($errors, $scenario, 'priority', Scenario::PRIORITIES, $at);

            $module = $scenario['module'] ?? null;
            if (is_string($module) && ! in_array($module, $enabledModules, true)) {
                $errors[] = "{$at}.module \"{$module}\" is not enabled (enabled: " . implode(', ', $enabledModules) . ')';
            }

            $confidence = $scenario['confidence'] ?? null;
            if (! is_int($confidence) && ! is_float($confidence)) {
                $errors[] = "{$at}.confidence must be a number between 0 and 1";
            } elseif ($confidence < 0 || $confidence > 1) {
                $errors[] = "{$at}.confidence must be between 0 and 1";
            }
        }

        return $errors;
    }

    /**
     * @param list<string>         $errors
     * @param array<string, mixed> $scenario
     * @param list<string>         $allowed
     */
    private function enum(array &$errors, array $scenario, string $field, array $allowed, string $at): void
    {
        $value = $scenario[$field] ?? null;
        if (is_string($value) && ! in_array($value, $allowed, true)) {
            $errors[] = "{$at}.{$field} must be one of: " . implode(', ', $allowed);
        }
    }
}
