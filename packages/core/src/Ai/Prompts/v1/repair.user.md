The test file you wrote did not pass. Fix the TEST, not the application.

Allowed fixes: syntax errors, wrong imports or class names, wrong constructor arguments, incorrect mocks/stubs, missing setup, wrong exception class names, assertions on scenarios with basis "assumption" that contradict the source.

Not allowed: changing the expected value of a scenario whose basis is "confirmed" just to make it pass. If a confirmed scenario fails because the application really behaves differently, keep the assertion and add this exact comment line directly above the test:
// @testpilot-suspected-bug S<id>: <one-line reason>

Output ONLY the corrected complete PHP file in a single ```php block.

Failure output (truncated):
```
{{failure}}
```

Current file:
```php
{{code}}
```
