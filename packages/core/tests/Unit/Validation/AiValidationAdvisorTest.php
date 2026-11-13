<?php

declare(strict_types=1);

use TestPilot\Ai\AiGateway;
use TestPilot\Ai\AiRequest;
use TestPilot\Ai\ModelRouter;
use TestPilot\Ai\Providers\FakeProvider;
use TestPilot\Exceptions\InvalidAiResponseException;
use TestPilot\Security\CodeSafetyChecker;
use TestPilot\Validation\AiValidationAdvisor;
use TestPilot\Validation\FormRequestRulesExtractor;
use TestPilot\Validation\RulesExtraction;
use TestPilot\Validation\ValidationCaseGenerator;
use TestPilot\Validation\ValidationTestBuilder;

/** StoreOrderRequest has 'sku' => regex:/^[A-Z]{3}-\d+$/ (Q1) and 'notes' => a closure rule (Q2). */
function aiOrderRules(): RulesExtraction
{
    $file = fixtures('app/Http/Requests/StoreOrderRequest.php');

    return (new FormRequestRulesExtractor())->extract((string) file_get_contents($file), 'App\Http\Requests\StoreOrderRequest', 'app/Http/Requests/StoreOrderRequest.php');
}

function aiReply(array $values = [], array $gaps = [], array $cases = []): string
{
    return json_encode(['schema_version' => '1', 'values' => $values, 'cases' => $cases, 'gaps' => $gaps, 'notes' => '']);
}

/** @return array{0: AiValidationAdvisor, 1: FakeProvider, 2: RulesExtraction, 3: array} */
function aiAdvisor(string ...$replies): array
{
    $fake = new FakeProvider(array_values($replies));
    $rules = aiOrderRules();

    return [new AiValidationAdvisor(new AiGateway($fake)), $fake, $rules, (new ValidationCaseGenerator())->generate($rules)];
}

function aiAdvise(AiValidationAdvisor $advisor, RulesExtraction $rules, array $generated)
{
    $request = $advisor->buildRequest($rules, $generated['baseline'], (string) file_get_contents(fixtures('app/Http/Requests/StoreOrderRequest.php')));

    return $advisor->advise($rules, $request, 'App\Http\Controllers\OrderController::store');
}

it('asks only about rules the deterministic engine cannot handle', function () {
    $questions = AiValidationAdvisor::questions(aiOrderRules());

    expect($questions)->toHaveCount(2)
        ->and($questions['Q1'])->toMatchArray(['field' => 'sku', 'kind' => 'regex', 'pattern' => '/^[A-Z]{3}-\d+$/'])
        ->and($questions['Q2'])->toMatchArray(['field' => 'notes', 'kind' => 'custom', 'pattern' => null]);
});

it('sends a validation-purpose request with the rules, baseline and questions', function () {
    [$advisor, $fake, $rules, $generated] = aiAdvisor(aiReply([['question' => 'Q1', 'valid' => 'ABC-1', 'invalid' => 'abc-1']]));
    aiAdvise($advisor, $rules, $generated);

    /** @var AiRequest $sent */
    $sent = $fake->requests[0];
    expect($sent->purpose)->toBe('validation')
        ->and($sent->promptVersion)->toBe('validation@v2')
        ->and($sent->context)->toContain('- sku: required|regex:/^[A-Z]{3}-\d+$/', 'Valid payload TestPilot built')
        ->and($sent->context)->toContain('Eligible fields for additional cases')
        ->and($sent->task)->toContain('Q1 · sku · regex:/^[A-Z]{3}-\d+$/', 'Q2 · notes · Closure rule');
});

it('adds diverse AI-proposed numeric cases only after locally checking their outcomes', function () {
    $file = <<<'PHP'
    <?php
    namespace App\Http\Requests;
    use Illuminate\Foundation\Http\FormRequest;
    final class AmountRequest extends FormRequest
    {
        public function rules(): array
        {
            return [
                'amount' => ['required', 'numeric', 'min:0', 'max:1000000'],
                'tax_rate' => ['sometimes', 'numeric', 'between:0,1'],
                'discount' => ['filled', 'numeric', 'min:0'],
            ];
        }
    }
    PHP;
    $rules = (new FormRequestRulesExtractor())->extract($file, 'App\Http\Requests\AmountRequest', 'app/Http/Requests/AmountRequest.php');
    $generated = (new ValidationCaseGenerator())->generate($rules);
    $fake = new FakeProvider([aiReply([], [], [
        ['field' => 'amount', 'action' => 'set', 'value' => null, 'reason' => 'null amount'],
        ['field' => 'amount', 'action' => 'set', 'value' => '100', 'reason' => 'numeric string'],
        ['field' => 'tax_rate', 'action' => 'remove', 'reason' => 'omit optional tax rate'],
        ['field' => 'tax_rate', 'action' => 'set', 'value' => 1.5, 'reason' => 'above upper bound'],
        ['field' => 'discount', 'action' => 'remove', 'reason' => 'filled permits an omitted field'],
        ['field' => 'amount', 'action' => 'set', 'value' => 0, 'reason' => 'repeat minimum boundary'],
    ])]);
    $advisor = new AiValidationAdvisor(new AiGateway($fake));
    $request = $advisor->buildRequest($rules, $generated['baseline'], $file, maxCases: 6);
    $advice = $advisor->advise($rules, $request);
    $plan = AiValidationAdvisor::apply($generated, $rules, $advice);
    $aiCases = array_values(array_filter($plan['scenarios'], static fn (array $scenario): bool => ($scenario['meta']['ai'] ?? false) && ($scenario['meta']['rule'] ?? null) === 'AI-proposed numeric input'));

    expect($advice->cases)->toHaveCount(6)
        ->and($advice->cases[0])->toMatchArray(['field' => 'amount', 'value' => null, 'expect' => 'error'])
        ->and($advice->cases[1])->toMatchArray(['field' => 'amount', 'value' => '100', 'expect' => 'no_error'])
        ->and($advice->cases[2])->toMatchArray(['field' => 'tax_rate', 'action' => 'remove', 'expect' => 'no_error'])
        ->and($advice->cases[3])->toMatchArray(['field' => 'tax_rate', 'value' => 1.5, 'expect' => 'error'])
        ->and($advice->cases[4])->toMatchArray(['field' => 'discount', 'action' => 'remove', 'expect' => 'no_error'])
        ->and($aiCases)->toHaveCount(5)
        ->and(collect($aiCases)->every(fn (array $scenario): bool => $scenario['selected'] && $scenario['basis'] === 'confirmed'))->toBeTrue()
        ->and($request->meta['validation_max_cases'])->toBe(6);
});

it('uses regex values only after preg_match confirms them, and keeps them confirmed', function () {
    [$advisor, , $rules, $generated] = aiAdvisor(aiReply([
        ['question' => 'Q1', 'valid' => 'ABC-123', 'invalid' => 'abc-123', 'reason' => 'lowercase letters break [A-Z]'],
    ]));
    $advice = aiAdvise($advisor, $rules, $generated);
    $plan = AiValidationAdvisor::apply($generated, $rules, $advice);
    $sku = array_values(array_filter($plan['scenarios'], fn ($s) => ($s['meta']['field'] ?? null) === 'sku' && ($s['meta']['ai'] ?? false)));

    expect($advice->values['Q1']['verified'])->toBeTrue()
        ->and($plan['baseline']['sku'])->toBe('ABC-123')
        ->and($plan['baseline_issues'])->not->toContain("'sku' must match a regular expression.")
        ->and($sku)->toHaveCount(2)
        ->and($sku[0])->toMatchArray(['basis' => 'confirmed', 'selected' => true, 'type' => 'negative'])
        ->and($sku[0]['meta'])->toMatchArray(['value' => 'abc-123', 'expect' => 'error', 'todo' => null, 'verified' => true])
        ->and($sku[0]['basis_source'])->toContain('checked locally with preg_match')
        ->and($sku[1]['meta'])->toMatchArray(['value' => 'ABC-123', 'expect' => 'no_error'])
        ->and(array_column($plan['scenarios'], 'id'))->toBe(array_map(fn ($i) => 'S' . $i, range(1, count($plan['scenarios']))));
});

it('retries once with the exact preg_match failure, then accepts the corrected answer', function () {
    [$advisor, $fake, $rules, $generated] = aiAdvisor(
        aiReply([['question' => 'Q1', 'valid' => 'abc-123', 'invalid' => 'ABC-123']]),
        aiReply([['question' => 'Q1', 'valid' => 'XYZ-9', 'invalid' => 'XYZ9']]),
    );
    $advice = aiAdvise($advisor, $rules, $generated);

    expect($fake->requests)->toHaveCount(2)
        ->and(array_slice($fake->requests[1]->messages, -1)[0]["content"])->toContain('does not match /^[A-Z]{3}-\d+$/ (checked with preg_match)')
        ->and($advice->calls)->toBe(2)
        ->and($advice->values['Q1'])->toMatchArray(['valid' => 'XYZ-9', 'invalid' => 'XYZ9', 'verified' => true]);
});

it('discards values that still fail the check, leaving the case for a human', function () {
    $wrong = aiReply([['question' => 'Q1', 'valid' => 'nope', 'invalid' => 'ABC-1']]);
    [$advisor, , $rules, $generated] = aiAdvisor($wrong, $wrong);
    $advice = aiAdvise($advisor, $rules, $generated);
    $plan = AiValidationAdvisor::apply($generated, $rules, $advice);
    $todo = array_values(array_filter($plan['scenarios'], fn ($s) => ($s['meta']['field'] ?? null) === 'sku' && str_starts_with((string) $s['meta']['rule'], 'regex')));

    expect($advice->values)->toBe([])
        ->and($advice->rejected[0]['question'])->toBe('Q1')
        ->and($todo)->toHaveCount(1)
        ->and($todo[0]['meta']['todo'])->not->toBeNull()
        ->and($plan['baseline_issues'])->toContain("'sku' must match a regular expression.");
});

it('keeps custom-rule values as unselected assumptions', function () {
    [$advisor, , $rules, $generated] = aiAdvisor(aiReply([
        ['question' => 'Q1', 'valid' => 'ABC-1', 'invalid' => '1-ABC'],
        ['question' => 'Q2', 'valid' => 'Leave at the door', 'invalid' => 'x', 'reason' => 'closure always passes', 'confidence' => 0.99],
    ]));
    $advice = aiAdvise($advisor, $rules, $generated);
    $plan = AiValidationAdvisor::apply($generated, $rules, $advice);
    $notes = array_values(array_filter($plan['scenarios'], fn ($s) => ($s['meta']['field'] ?? null) === 'notes' && ($s['meta']['ai'] ?? false)));

    expect($advice->values['Q2'])->toMatchArray(['verified' => false, 'confidence' => 0.8])
        ->and($notes)->toHaveCount(1)
        ->and($notes[0])->toMatchArray(['basis' => 'assumption', 'selected' => false])
        ->and($notes[0]['description'])->toContain('not verifiable locally');
});

it('rejects answers to questions that were not asked and values that break other rules', function () {
    [$advisor, , , ] = aiAdvisor();
    $file = <<<'PHP'
    <?php
    namespace App\Http\Requests;
    use Illuminate\Foundation\Http\FormRequest;
    final class CodeRequest extends FormRequest
    {
        public function rules(): array
        {
            return ['code' => ['required', 'string', 'max:5', 'regex:/^[a-z]+$/']];
        }
    }
    PHP;
    $rules = (new FormRequestRulesExtractor())->extract($file, 'App\Http\Requests\CodeRequest', 'app/Http/Requests/CodeRequest.php');
    $generated = (new ValidationCaseGenerator())->generate($rules);
    $bad = aiReply([
        ['question' => 'Q9', 'valid' => 'a', 'invalid' => 'B'],
        ['question' => 'Q1', 'valid' => 'abcdefgh', 'invalid' => 'ABC'],
    ]);
    $fake = new FakeProvider([$bad, $bad]);
    $advisor = new AiValidationAdvisor(new AiGateway($fake));
    $advice = $advisor->advise($rules, $advisor->buildRequest($rules, $generated['baseline'], $file));

    expect($advice->values)->toBe([])
        ->and(array_column($advice->rejected, 'reason'))->toContain('"Q9" is not one of the questions asked', 'valid value "abcdefgh" breaks max:5 on the same field');
});

it('passes rule gaps through as findings, never as tests', function () {
    [$advisor, , $rules, $generated] = aiAdvisor(aiReply(
        [['question' => 'Q1', 'valid' => 'ABC-1', 'invalid' => 'abc']],
        [
            ['field' => 'password', 'severity' => 'HIGH', 'issue' => 'No max length; bcrypt truncates at 72 bytes', 'suggestion' => 'max:72', 'basis_source' => 'line 61'],
            ['field' => 'x', 'severity' => 'urgent', 'issue' => 'odd severity'],
            ['field' => 'y', 'issue' => ''],
        ],
    ));
    $advice = aiAdvise($advisor, $rules, $generated);
    $plan = AiValidationAdvisor::apply($generated, $rules, $advice);

    expect($advice->gaps)->toHaveCount(2)
        ->and($advice->gaps[0])->toMatchArray(['field' => 'password', 'severity' => 'high', 'suggestion' => 'max:72'])
        ->and($advice->gaps[1]['severity'])->toBe('medium')
        ->and(count($plan['scenarios']))->toBe(count($generated['scenarios']) + 1); // only the regex "accepts" case was added
});

it('fails loudly when the reply is not JSON after one retry', function () {
    [$advisor, , $rules, $generated] = aiAdvisor('I think the rules look fine.', 'Still prose.');

    expect(fn () => aiAdvise($advisor, $rules, $generated))->toThrow(InvalidAiResponseException::class);
});

it('routes the validation purpose to its own model, then the plan model', function () {
    $request = new AiRequest('validation', 's', [['role' => 'user', 'content' => 'u']]);

    expect((new ModelRouter(['plan' => 'plan-model']))->route($request))->toBe('plan-model')
        ->and((new ModelRouter(['plan' => 'plan-model', 'validation' => 'cheap']))->route($request))->toBe('cheap')
        ->and((new ModelRouter(['validation' => 'cheap', 'critical' => 'big']))->route($request->withMeta(['criticality' => 'high'])))->toBe('big');
});

it('writes a safe Pest file that includes the checked AI cases', function () {
    [$advisor, , $rules, $generated] = aiAdvisor(aiReply([['question' => 'Q1', 'valid' => 'ABC-123', 'invalid' => 'abc-123']]));
    $plan = AiValidationAdvisor::apply($generated, $rules, aiAdvise($advisor, $rules, $generated));

    $code = (new ValidationTestBuilder())->build([
        'method' => 'POST',
        'uri' => '/api/orders',
        'baseline' => $plan['baseline'],
        'scenarios' => $plan['scenarios'],
        'request_class' => 'App\Http\Requests\StoreOrderRequest',
    ]);

    expect($code)->toContain("'sku' => 'ABC-123'", "data_set(\$payload, 'sku', 'abc-123');", 'rejects a value that does not match the pattern (AI-proposed)')
        ->and((new CodeSafetyChecker())->check($code))->toBe([]);
});
