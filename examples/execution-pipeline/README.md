# Execution Pipeline Examples

This directory demonstrates compiler-visible runtime execution pipeline outputs.

Run these commands from the Foundry framework repository root with `php bin/foundry ...`.
If you copy the same example into a generated app, switch to `foundry ...`.

- **Example A**: inspect canonical pipeline stages.
- **Example B**: inspect a compiled feature execution plan.
- **Example C**: pipeline-focused graph visualization.

## Example A - Inspect Pipeline

```bash
php bin/foundry inspect pipeline --json
```

Representative payload: `inspect/pipeline.sample.json`.

## Example B - Inspect Execution Plan

```bash
php bin/foundry inspect execution-plan publish_post --json
php bin/foundry inspect guards publish_post --json
php bin/foundry inspect interceptors --stage=auth --json
php bin/foundry verify pipeline --json
```

Representative payload: `inspect/execution_plan.sample.json`.

## Example C - Visualize Pipeline

```bash
php bin/foundry inspect graph --pipeline --format=mermaid --json
```

Representative diagram: `visualize/pipeline.mermaid`.
