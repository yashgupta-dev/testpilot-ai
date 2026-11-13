# Architecture

## Layers

```
ui (Vue SPA, built into packages/laravel/dist)
   │  JSON over /testpilot/api, CSRF-protected, behind Authorize middleware
   ▼
packages/laravel  ── Http controllers → Application services → Jobs → Eloquent models
   │  implements core contracts (UsageRecorder) and wires core from config
   ▼
packages/core     ── Discovery · Configuration · Ai · Generation · Verification · Execution · Storage · Security · Risk
```

Rules:

- `core` has no Laravel dependency. It can be used by a plain-PHP CLI (planned) or other frameworks.
- Engines never touch the database. Laravel services persist results.
- Data crosses layers as readonly DTOs (`TestTarget`, `ResolvedConfig`, `ScenarioPlan`, `GenerationOutcome`, `RunResult`).

## Core components

| Namespace | Responsibility |
|---|---|
| `Discovery` | `ProjectScanner` → `FileFinder` → php-parser AST → `DeclarationCollector` → `TargetBuilder` (inheritance, traits). `AttributeReader` maps attribute arguments using reflection on TestPilot's own attribute classes only. |
| `Configuration` | `ConfigurationResolver` merges defaults → class → method and clamps with `PolicyLimits`; produces a trace. |
| `Risk` | `RiskScorer` returns a score with one reason per point. |
| `Ai` | `AiProviderInterface` + Anthropic / OpenAI-compatible / fake providers. `AiGateway` is the only path to a provider: policy → budget → redaction → call → usage record. Prompts are versioned files. |
| `Generation` | `ScenarioPlanner` (JSON plan + validator + one retry), `TestCodeGenerator` (Pest file + repair), `CodeExtractor`. |
| `Fuzz` | `FuzzDatasetBuilder`: seeded, deterministic boundary + random values exported as a PHP dataset literal. |
| `Verification` | `GenerationPipeline`: safety → lint → run → repair loop → stability → mutation. Returns `GenerationOutcome` (verified, suspected_bug, failed, unsafe). |
| `Execution` | `PestRunner` (Symfony Process, array args, minimal env, timeout), JUnit and mutation parsers. |
| `Storage` | `TestFileWriter` (pending vs approved dirs, never overwrites developer files), `ProvenanceHeader`, `SourceFingerprinter`. |
| `Security` | `PathGuard`, `EnvironmentGuard`, `CodeSafetyChecker`. |

## Lifecycles

Target: `not_planned → planning → plan_ready → generating → awaiting_approval → approved` (`stale` after a source change, `needs_attention` after a failure, `skipped` for `#[SkipTest]`).

Generated test: `generating → verified | suspected_bug | failed | unsafe → approved | rejected` (`approved → stale` when the source fingerprint changes).

Run: `queued → running → completed | failed | cancelled`. A run for the same tests and commit is reused while active (idempotency key).

## Database

`testpilot_targets`, `testpilot_plans`, `testpilot_generated_tests`, `testpilot_runs`, `testpilot_run_results`, `testpilot_ai_requests` (metadata only, no prompts), `testpilot_audit_logs`. ULID keys; JSON columns for flexible data, indexed columns for status, risk and time.

## Decisions (ADR summary)

1. **Pest-first, Pest 3+.** Generated tests use Pest; the runner calls the project's own binary, so Pest 3, 4 or 5 all work.
2. **Dashboard as a self-contained SPA inside the package** (like Horizon), not Filament: no UI framework imposed on host apps, pixel control to match the design, and assets ship pre-built so no `npm` step is needed in the host app.
3. **Human approval is mandatory.** The pipeline can only produce pending candidates.
4. **Confirmed vs assumption.** Scenarios must cite where an expectation comes from; the repair prompt may not weaken a confirmed assertion and instead marks a suspected bug.
5. **Mutation results are best-effort parsed** from Pest output and gated per file; raw output is always kept.
