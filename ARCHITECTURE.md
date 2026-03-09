# Foundry Architecture

## Goals
Foundry is an explicit, deterministic, LLM-first PHP web framework focused on:
- contract-driven feature execution
- generated indexes over runtime scanning
- inspectable behavior and machine-readable metadata
- strong automated verification and testing

## Runtime Shape
- `app/features/*` is source-of-truth behavior.
- `app/.foundry/build/*` is canonical compiler output (graph + projections + manifests + diagnostics).
- `app/generated/*` is a compatibility mirror for runtime loading.
- `src/*` contains stable framework core.

Hot request path (`kind=http`):
1. route match from generated index
2. feature definition load from generated index
3. authentication + authorization
4. input schema validation
5. optional transaction begin
6. action execution (`FeatureAction`)
7. output schema validation
8. commit/rollback
9. response emission + trace/audit

## Core Subsystems
- `Compiler`: canonical graph IR, pass pipeline, diagnostics, projections, impact analysis, migration hooks, extension hooks.
- `Feature`: definitions, loading, execution pipeline.
- `Schema`: schema registry + validator.
- `DB`: named-query loader/registry + PDO executor + transactions.
- `Auth`: explicit auth context + authorization engine.
- `Cache`: contract registry + key builder + manager.
- `Queue`: job contracts, dispatcher, drivers, worker, retry policy.
- `Events`: event contracts + explicit subscribers.
- `Scheduler`: scheduled task definitions and runner.
- `Storage`: local and S3-like drivers.
- `Webhook`: signing and verification.
- `AI`: provider abstraction, request/response contracts, cache integration.
- `Observability`: structured logs, traces, metrics, audit events.
- `Generation`: feature/test/context/migration generation.
- `Verification`: feature/contracts/auth/cache/events/jobs/migrations checks.
- `CLI`: compile/inspect/verify/migrate/runtime command surface.

## Determinism Rules
- Graph and projection outputs are stable and sorted.
- Build artifacts are explicit files, not opaque archives.
- Generated output is plain PHP arrays for fast load time.
- Feature folders are explicit; no hidden runtime discovery in hot path.

## Safety Rules
- Feature actions only receive the `FeatureAction` contract inputs.
- No hidden DI magic in action execution.
- All inspect/verify/planning commands support stable JSON mode.
