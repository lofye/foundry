# Phase 0D Examples

This directory demonstrates compiler-visible runtime execution pipeline outputs.

- **Example A**: inspect canonical pipeline stages.
- **Example B**: inspect a compiled feature execution plan.
- **Example C**: pipeline-focused graph visualization.

## Example A - Inspect Pipeline

```bash
php vendor/bin/foundry inspect pipeline --json
```

Representative payload: `inspect/pipeline.sample.json`.

## Example B - Inspect Execution Plan

```bash
php vendor/bin/foundry inspect execution-plan publish_post --json
php vendor/bin/foundry inspect guards publish_post --json
php vendor/bin/foundry inspect interceptors --stage=auth --json
php vendor/bin/foundry verify pipeline --json
```

Representative payload: `inspect/execution_plan.sample.json`.

## Example C - Visualize Pipeline

```bash
php vendor/bin/foundry graph visualize --pipeline --format=mermaid --json
```

Representative diagram: `visualize/pipeline.mermaid`.
