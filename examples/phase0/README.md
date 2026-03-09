# Phase 0 Examples

## Example A: Simple App Compile

Source files (authored truth):
- `app/features/publish_post/feature.yaml`
- `app/features/publish_post/input.schema.json`
- `app/features/publish_post/output.schema.json`
- `app/features/publish_post/queries.sql`
- `app/features/publish_post/permissions.yaml`
- `app/features/publish_post/events.yaml`
- `app/features/publish_post/jobs.yaml`
- `app/features/publish_post/cache.yaml`

Compile:
```bash
php vendor/bin/foundry compile graph --json
```

Expected outputs:
- `app/.foundry/build/graph/app_graph.json`
- `app/.foundry/build/projections/routes_index.php`
- `app/.foundry/build/projections/feature_index.php`
- `app/.foundry/build/diagnostics/latest.json`

Quick check:
```bash
php vendor/bin/foundry inspect graph --json
php vendor/bin/foundry inspect build --json
```

## Example B: Impact Analysis

Change:
- edit `app/features/publish_post/input.schema.json`

Inspect impact:
```bash
php vendor/bin/foundry inspect impact --file=app/features/publish_post/input.schema.json --json
```

Expected response includes:
- affected nodes
- affected features
- affected projections
- recommended verification commands
- recommended tests
- risk level (`low|medium|high`)

## Example C: Migration / Codemod

Outdated manifest (`version: 1`, legacy `llm.risk` field):
```bash
php vendor/bin/foundry inspect migrations --json
php vendor/bin/foundry migrate specs --dry-run --json
php vendor/bin/foundry migrate specs --write --json
```

Typical rewrite performed by core migration rule:
- `version: 1` -> `version: 2`
- `llm.risk` -> `llm.risk_level`
- `auth.strategy` -> `auth.strategies`
- route methods normalized to uppercase

## Example D: Extension Hook

Inspect registered compiler extensions:
```bash
php vendor/bin/foundry inspect extensions --json
```

Extension foundation supports contributions for:
- pass stages
- projection emitters
- migration rules

A minimal extension can register one projection emitter and inspect it via the command above.
