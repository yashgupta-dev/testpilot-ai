<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use TestPilot\Security\CodeSafetyChecker;
use TestPilot\Validation\FormRequestRulesExtractor;
use TestPilot\Validation\RulesExtraction;
use TestPilot\Validation\ValidationCaseGenerator;
use TestPilot\Validation\ValidationRule;
use TestPilot\Validation\ValidationTestBuilder;

function orderRules(): RulesExtraction
{
    $file = fixtures('app/Http/Requests/StoreOrderRequest.php');

    return (new FormRequestRulesExtractor())->extract((string) file_get_contents($file), 'App\Http\Requests\StoreOrderRequest', 'app/Http/Requests/StoreOrderRequest.php');
}

function casesByName(array $scenarios): array
{
    return array_column($scenarios, null, 'name');
}

describe('FormRequestRulesExtractor', function () {
    it('reads pipe strings, rule arrays and Rule:: helpers without running the class', function () {
        $rules = orderRules();
        $fields = array_column(array_map(fn ($f) => $f->toArray(), $rules->fields), null, 'field');

        expect($rules->complete)->toBeTrue()
            ->and($rules->authorize)->toBe('true')
            ->and(array_keys($fields))->toBe(['email', 'quantity', 'status', 'coupon', 'reference', 'password', 'items', 'items.*.price', 'sku', 'notes', 'delivery_date'])
            ->and(array_column($fields['email']['rules'], 'name'))->toBe(['required', 'email', 'max'])
            ->and($fields['quantity']['type'])->toBe('integer')
            ->and($fields['status']['rules'][1])->toMatchArray(['name' => 'in', 'params' => ['draft', 'placed']])
            ->and($fields['coupon']['rules'][2])->toMatchArray(['name' => 'exists', 'params' => ['coupons', 'code']])
            ->and($fields['reference']['rules'][2]['note'])->toContain('->ignore()')
            ->and($fields['password']['rules'][2])->toMatchArray(['name' => 'password', 'params' => ['10']])
            ->and($fields['sku']['rules'][1])->toMatchArray(['name' => 'regex', 'params' => ['/^[A-Z]{3}-\d+$/']])
            ->and($fields['notes']['rules'][2]['kind'])->toBe(ValidationRule::CUSTOM)
            ->and($fields['items.*.price']['type'])->toBe('numeric');
    });

    it('reports rules built at runtime instead of guessing', function () {
        $file = fixtures('app/Http/Requests/DynamicRulesRequest.php');
        $rules = (new FormRequestRulesExtractor())->extract((string) file_get_contents($file), 'App\Http\Requests\DynamicRulesRequest');

        expect($rules->complete)->toBeFalse()
            ->and($rules->fields)->toBe([])
            ->and($rules->authorize)->toBe('dynamic')
            ->and($rules->warnings[0])->toContain('array_merge(…)')->toContain('built at runtime');
    });

    it('reports a missing class or rules() method', function () {
        $rules = (new FormRequestRulesExtractor())->extract("<?php\nnamespace A; class B {}", 'A\B');

        expect($rules->complete)->toBeFalse()->and($rules->warnings[0])->toContain('has no rules() method');
    });
});

describe('ValidationCaseGenerator', function () {
    it('builds a payload that satisfies every readable rule', function () {
        $baseline = (new ValidationCaseGenerator())->generate(orderRules())['baseline'];

        expect($baseline['email'])->toBe('tp.user@example.com')
            ->and($baseline['quantity'])->toBe(1)
            ->and($baseline['status'])->toBe('draft')
            ->and($baseline['password'])->toBe($baseline['password_confirmation'])
            ->and(strlen($baseline['password']))->toBeGreaterThanOrEqual(10)
            ->and($baseline['items'][0])->toBe(['price' => 0.0])
            ->and($baseline['delivery_date'])->toBe('2099-12-31');
    });

    it('turns each rule into concrete cases with confirmed expectations', function () {
        $cases = casesByName((new ValidationCaseGenerator())->generate(orderRules())['scenarios']);

        expect($cases['email · required — rejects a missing value']['meta'])->toMatchArray(['field' => 'email', 'action' => 'remove', 'expect' => 'error'])
            ->and($cases['email · email — rejects an invalid email']['meta']['value'])->toBe('abc@')
            ->and($cases['email · max:255 — max boundary']['meta']['todo'])->toContain('254 characters')
            ->and($cases['email · max:255 — max boundary']['selected'])->toBeFalse()
            ->and($cases['quantity · min:1 — rejects 0 (min 1)']['meta']['value'])->toBe(0)
            ->and($cases['quantity · integer — rejects a decimal number']['meta']['value'])->toBe(1.5)
            ->and($cases['status · in:draft,placed — rejects a value outside the allowed list']['basis'])->toBe('confirmed')
            ->and($cases['password · confirmed — rejects a mismatched confirmation']['meta']['target_field'])->toBe('password_confirmation')
            ->and($cases['items.*.price · between:0,10000 — rejects 10001 (max 10000)']['meta']['field'])->toBe('items.0.price')
            ->and($cases['delivery_date · after:today — rejects a date in the past']['meta']['value'])->toBe('2000-01-01')
            ->and($cases['coupon · nullable — accepts null']['meta']['expect'])->toBe('no_error');
    });

    it('marks cases it cannot generate as unselected with a reason', function () {
        $cases = casesByName((new ValidationCaseGenerator())->generate(orderRules())['scenarios']);

        $unique = $cases['reference · unique:orders,reference (with ->ignore()) — rejects a duplicate value'];
        $regex = $cases['sku · regex:/^[A-Z]{3}-\d+$/ — pattern check'];
        $closure = $cases['notes · Closure rule — custom rule'];

        expect($unique['selected'])->toBeFalse()->and($unique['meta']['todo'])->toContain('factory')
            ->and($regex['selected'])->toBeFalse()
            ->and($closure['selected'])->toBeFalse();
    });

    it('keeps length boundaries valid for the field format', function () {
        $email = ValidationCaseGenerator::emailOfLength(120);

        expect(strlen($email))->toBe(120)
            ->and(filter_var($email, FILTER_VALIDATE_EMAIL))->toBe($email)
            ->and(ValidationCaseGenerator::emailOfLength(5))->toBeNull();
    });

    it('keeps the confirmation field in sync when changing a confirmed field', function () {
        $cases = casesByName((new ValidationCaseGenerator())->generate(orderRules())['scenarios']);

        expect($cases['password · Password (min 10) — rejects a password shorter than 10 characters']['meta']['also_set'])->toBe(['password_confirmation' => str_repeat('a', 9)])
            ->and($cases['password · confirmed — rejects a mismatched confirmation']['meta']['also_set'])->toBe([]);
    });

    it('only claims the full payload is valid when every rule was understood', function () {
        $result = (new ValidationCaseGenerator())->generate(orderRules());
        $first = $result['scenarios'][0];

        expect($first['name'])->toBe('Accepts a fully valid payload')
            ->and($first['selected'])->toBeFalse() // exists, regex and a closure make it unprovable
            ->and(implode(' ', $result['baseline_issues']))->toContain("'coupon' must exist in coupons")->toContain("'sku' must match a regular expression");
    });

    it('numbers scenarios S1, S2, … for the plan UI', function () {
        $ids = array_column((new ValidationCaseGenerator())->generate(orderRules())['scenarios'], 'id');

        expect($ids[0])->toBe('S1')->and(end($ids))->toBe('S' . count($ids))->and(count($ids))->toBeGreaterThan(25);
    });

    it('adds capped, reproducible numeric variations without repeating boundary inputs', function () {
        $generator = new ValidationCaseGenerator();
        $first = $generator->generate(orderRules(), 3, 17);
        $second = $generator->generate(orderRules(), 3, 17);
        $variations = array_values(array_filter($first['scenarios'], static fn (array $scenario): bool => ($scenario['meta']['rule'] ?? null) === 'additional numeric input'));
        $again = array_values(array_filter($second['scenarios'], static fn (array $scenario): bool => ($scenario['meta']['rule'] ?? null) === 'additional numeric input'));

        expect($variations)->toHaveCount(3)
            ->and(array_column($variations, 'meta'))->toBe(array_column($again, 'meta'))
            ->and(array_unique(array_map(static fn (array $scenario): string => $scenario['meta']['field'] . ':' . $scenario['meta']['value'], $variations)))->toHaveCount(3)
            ->and(array_unique(array_column(array_column($variations, 'meta'), 'field')))->toHaveCount(2)
            ->and(count($generator->generate(orderRules(), 200, 17)['scenarios']))->toBeLessThanOrEqual(count($generator->generate(orderRules())['scenarios']) + 20);
    });
});

describe('ValidationTestBuilder', function () {
    it('writes a safe, valid Pest file with one test per selected case', function () {
        $result = (new ValidationCaseGenerator())->generate(orderRules());
        $code = (new ValidationTestBuilder())->build([
            'request_class' => 'App\Http\Requests\StoreOrderRequest',
            'method' => 'POST',
            'uri' => '/api/orders',
            'baseline' => $result['baseline'],
            'scenarios' => $result['scenarios'],
            'authenticate' => true,
            'test_case' => 'Tests\TestCase',
            'refresh_database' => true,
        ]);

        $selected = count(array_filter($result['scenarios'], fn ($s) => $s['selected']));

        expect((new CodeSafetyChecker())->check($code))->toBe([])
            ->and((new ParserFactory())->createForNewestSupportedVersion()->parse($code))->not->toBeEmpty()
            ->and(substr_count($code, "\nit('["))->toBe($selected)
            ->and($code)->toContain('uses(\Tests\TestCase::class);')
            ->toContain('uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);')
            ->toContain('$this->actingAs(\App\Models\User::factory()->create());')
            ->toContain("\\Illuminate\\Support\\Arr::forget(\$payload, 'email');")
            ->toContain("data_set(\$payload, 'quantity', 101);")
            ->toContain('->assertSuccessful()')
            ->toContain("->assertJsonValidationErrors(['email']);")
            ->toContain("->assertJsonMissingValidationErrors(['coupon']);")
            ->not->toContain('pattern check');
    });

    it('sends GET payloads as a query string', function () {
        $code = (new ValidationTestBuilder())->build([
            'request_class' => 'X', 'method' => 'GET', 'uri' => '/api/search', 'baseline' => ['q' => 'a'],
            'scenarios' => [['id' => 'S1', 'name' => 'q · required — rejects a missing value', 'selected' => true, 'meta' => ['field' => 'q', 'target_field' => 'q', 'action' => 'remove', 'expect' => 'error', 'todo' => null]]],
        ]);

        expect($code)->toContain("\$this->getJson('/api/search' . '?' . http_build_query(\$payload))");
    });
});
