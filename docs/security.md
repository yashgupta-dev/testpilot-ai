# Security model

TestPilot handles source code and executes generated code, so it is defensive by default. Policies always win over annotations and AI output.

| Risk | Control |
|---|---|
| Scanning runs application code | Files are parsed into ASTs only. A fixture defines a constant at top level and a test asserts it is never defined. |
| AI writes dangerous code | `CodeSafetyChecker` rejects shell/process calls, `eval`, backticks, `exit`, includes, dynamic calls, file writes, network functions, real HTTP clients, env/ini changes and `sleep` **before** execution. Unsafe code is stored for review but never run or written to disk. |
| Generated tests run with app privileges | Tests run in a separate PHP process via Symfony Process with array arguments (no shell), a timeout, `APP_ENV=testing` and an allow-listed environment (parent secrets are not inherited). For stronger isolation run the queue worker in a container with no network and a read-only source mount. |
| Running against production | `EnvironmentGuard` refuses to run tests when the environment is in `limits.blocked_environments`. The dashboard returns 404 in those environments unless explicitly allowed. |
| Path traversal / overwriting files | `PathGuard` resolves every path inside the project root and restricts writes to `pending_path` and `generated_path`. Approval refuses to overwrite a file without the `@testpilot-generated` marker. Asset serving is limited to two known files. |
| Leaking secrets to AI vendors | `Redactor` strips API keys, tokens, JWTs, private keys, credentials in DSNs and secret-looking assignments from every message. Only the target's source and the class outline (signatures) are sent. `TESTPILOT_AI_POLICY=local` allows only local models; `off` disables AI. `#[AI(enabled: false)]` opts a target out. |
| Cost overruns | Monthly budget enforced before every call; every call is recorded with tokens and estimated cost. |
| Unauthorised approvals | Dashboard access via `TestPilotDashboard::auth()`, approvals via `approveUsing()`. All state changes go through POST with CSRF protection and are written to the audit log with actor and IP. |
| Accepting tests generated for old code | Approval is refused if the target's source fingerprint changed since generation. |

Not yet implemented (planned): per-run Docker sandbox started by TestPilot itself, SSO-backed roles, retention pruning command.
