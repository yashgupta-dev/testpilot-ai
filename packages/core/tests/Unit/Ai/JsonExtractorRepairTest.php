<?php

declare(strict_types=1);

use TestPilot\Ai\JsonExtractor;
use TestPilot\Exceptions\InvalidAiResponseException;

it('reads JSON whose strings quote PHP namespaces with single backslashes', function () {
    $reply = '{"scenarios": [{"basis_source": "line 63: \Illuminate\Support\Facades\Hash::make($request->password)"}], "notes": "uses \App\Models\Admin",}';

    $data = JsonExtractor::object($reply);

    expect($data['scenarios'][0]['basis_source'])->toBe('line 63: \Illuminate\Support\Facades\Hash::make($request->password)')
        ->and($data['notes'])->toBe('uses \App\Models\Admin');
});

it('leaves valid escapes alone', function () {
    $valid = '{"a": "tab\\tquote\\" slash\\\\ unicode\\u00e9 newline\\n", "b": "C:\\\\path"}';

    expect(JsonExtractor::repair($valid))->toBe($valid)
        ->and(JsonExtractor::object($valid))->toBe(json_decode($valid, true));
});

it('still fails on prose', function () {
    expect(fn () => JsonExtractor::object('Here are the scenarios you asked for.'))->toThrow(InvalidAiResponseException::class);
});
