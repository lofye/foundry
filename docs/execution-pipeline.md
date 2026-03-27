# Foundry Execution Pipeline

The execution pipeline introduces deterministic runtime execution plans built from the canonical application graph.

Architecture sequence:

- compiler core: semantic compiler + canonical graph.
- extensions and migrations layer: extension/pack/migration/codemod/version compatibility model.
- architecture tools layer: graph-native doctor/visualization/prompt tooling.
- execution pipeline layer: execution pipeline, feature guards, and stage interceptors.

## Rules

- Runtime execution must be compiler-visible and graph-represented.
- No hidden middleware chain outside the pipeline model.
- Guards and interceptors must be inspectable via CLI and JSON output.
- Extension-contributed stages/interceptors use explicit extension interfaces.
- Diagnostics for pipeline conflicts flow through the existing diagnostics engine.

## Execution Pipeline

Core stage set:

1. `request_received`
2. `routing`
3. `before_auth`
4. `auth`
5. `before_validation`
6. `validation`
7. `before_action`
8. `action`
9. `after_action`
10. `response_serialization`
11. `response_send`

Compiler output now includes graph nodes for:

- `pipeline_stage:*`
- `execution_plan:*`
- `guard:*`
- `interceptor:*`

And projection artifacts:

- `pipeline_index.php`
- `execution_plan_index.php`
- `guard_index.php`
- `interceptor_index.php`

## Guards

Compiled guard types include:

- `authentication`
- `permission`
- `rate_limit`
- `csrf`
- `request_validation`
- `transaction`

Feature manifests remain source-of-truth. Guard nodes are compiled from feature definitions and linked into each `execution_plan` node.

## Interceptors

Interceptors are extension-registered and stage-bound.

Core extension provides baseline interceptors:

- `trace.request_received`
- `trace.response_send`

Interceptors are represented in graph/projection artifacts and attached to execution plans deterministically.

## Diagnostics

Pipeline diagnostics available in the execution pipeline:

- `FDY8001_FEATURE_REQUIRES_AUTH`
- `FDY8002_INTERCEPTOR_STAGE_CONFLICT`
- `FDY8003_CONFLICTING_RATE_LIMIT`
- `FDY8004_NON_DETERMINISTIC_PIPELINE_ORDER`

## CLI Surface

Inspect:

```bash
foundry inspect pipeline --json
foundry inspect execution-plan <feature|route> --json
foundry inspect guards --json
foundry inspect guards <feature> --json
foundry inspect interceptors --json
foundry inspect interceptors --stage=<stage> --json
```

Verify:

```bash
foundry verify pipeline --json
```

Visualize:

```bash
foundry inspect graph --pipeline --format=mermaid --json
```

## Runtime Behavior

`FeatureExecutor` now executes explicit stage loops using compiled execution plans and guard/interceptor metadata.

When pipeline projections are unavailable, runtime falls back to deterministic defaults derived from feature definitions, preserving compatibility.

## Development Loop

1. Edit source-of-truth feature files under `app/features/*`.
2. `foundry compile graph --json`
3. `foundry inspect execution-plan <feature> --json`
4. `foundry doctor --json`
5. `foundry verify pipeline --json`
6. `php vendor/bin/phpunit`
