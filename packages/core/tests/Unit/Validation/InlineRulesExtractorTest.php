<?php

declare(strict_types=1);

use TestPilot\Validation\InlineRulesExtractor;

function inlineExtract(string $body, ?int $declared = null): \TestPilot\Validation\RulesExtraction
{
    $code = "<?php\nnamespace App\\Http\\Controllers;\nuse Illuminate\\Http\\Request;\nuse Illuminate\\Support\\Facades\\Validator;\nclass C {\n    public function act(Request \$request) {\n{$body}\n    }\n}\n";

    return (new InlineRulesExtractor())->extract($code, 'App\Http\Controllers\C', 'act', 'app/Http/Controllers/C.php', $declared);
}

it('reads the patterns that throw Laravel\'s ValidationException', function (string $body, string $call) {
    $x = inlineExtract($body);

    expect($x->origin)->toBe('inline')
        ->and($x->inline)->toMatchArray(['call' => $call, 'error_mode' => 'laravel', 'error_status' => 422, 'standard_errors' => true])
        ->and(array_map(fn ($f) => $f->field, $x->fields))->toBe(['name'])
        ->and($x->label())->toBe("C::act() → {$call}");
})->with([
    '$request->validate' => ["\$request->validate(['name' => 'required|max:5']);", '$request->validate()'],
    'request()->validate' => ["request()->validate(['name' => 'required']);", 'request()->validate()'],
    'validateWithBag' => ["\$request->validateWithBag('login', ['name' => 'required']);", '$request->validateWithBag()'],
    '$this->validate with a variable' => ["\$rules = ['name' => 'required'];\n\$this->validate(\$request, \$rules);", '$this->validate()'],
    'Validator::make()->validate()' => ["Validator::make(\$request->all(), ['name' => 'required'])->validate();", 'Validator::make()'],
    'assigned Validator ->validated()' => ["\$v = Validator::make(\$request->all(), ['name' => 'required']);\n\$data = \$v->validated();", 'Validator::make()'],
    'validator() helper' => ["validator(\$request->all(), ['name' => 'required'])->validate();", 'validator()'],
]);

it('reads the status of a hand-written failure response', function (string $failure, int $status) {
    $x = inlineExtract("\$v = Validator::make(\$request->all(), ['name' => 'required']);\nif (\$v->fails()) { {$failure} }\nreturn response()->json(['ok' => true], 201);");

    expect($x->inline)->toMatchArray(['error_mode' => 'status', 'error_status' => $status, 'standard_errors' => false]);
})->with([
    'response()->json' => ["return response()->json(['errors' => \$v->errors()], 400);", 400],
    'abort' => ['abort(409);', 409],
    'response()' => ["return response('bad', 418);", 418],
]);

it('checks session errors when failures redirect back with ->withErrors()', function () {
    $x = inlineExtract("\$v = Validator::make(\$request->all(), ['name' => 'required']);\nif (\$v->fails()) { return back()->withErrors(\$v)->withInput(); }\nreturn redirect('/done');");

    expect($x->inline)->toMatchArray(['error_mode' => 'redirect_errors', 'error_status' => 302]);
});

it('cannot tell a bare redirect from success, and says how to fix it', function () {
    $x = inlineExtract("\$v = Validator::make(\$request->all(), ['name' => 'required']);\nif (\$v->fails()) { return redirect()->back(); }\nreturn redirect('/done');");

    expect($x->inline['error_mode'])->toBe('unknown')
        ->and(implode(' ', $x->warnings))->toContain('->withErrors(');
});

it('prefers a declared error status', function () {
    $x = inlineExtract("\$v = Validator::make(\$request->all(), ['name' => 'required']);\nif (\$v->fails()) { return \$this->fail(\$v); }", 400);

    expect($x->inline)->toMatchArray(['error_mode' => 'status', 'error_status' => 400]);
});

it('reports rules built at runtime instead of guessing', function () {
    $x = inlineExtract("\$request->validate(array_merge(\$this->base(), ['name' => 'required']));");

    expect($x->fields)->toBe([])
        ->and($x->warnings[0])->toContain('built at runtime');
});

it('warns about code that runs before validation and conditional validation', function () {
    $x = inlineExtract("Log::info('x');\n\$order = Order::create([]);\nif (\$request->has('name')) {\n    \$request->validate(['name' => 'required']);\n}");

    expect(implode(' ', $x->warnings))->toContain('Order::create() line')->toContain('only runs on some paths');
});

it('explains when there is no validation at all', function () {
    $x = inlineExtract("return response()->json([]);");

    expect($x->fields)->toBe([])
        ->and($x->warnings[0])->toContain('no FormRequest parameter and no $request->validate()');
});
