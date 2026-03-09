# Feature Definition Spec (Foundry v2)

## Required files per feature
`app/features/<feature>/`
- `feature.yaml`
- `action.php`
- `input.schema.json`
- `output.schema.json`
- `context.manifest.json`
- `tests/`

## Strongly encouraged files
- `queries.sql`
- `permissions.yaml`
- `cache.yaml`
- `events.yaml`
- `jobs.yaml`
- `prompts.md`

## `feature.yaml` required structure
- `version` (int)
- `feature` (snake_case)
- `kind` (`http|job|event_handler|scheduled|webhook_incoming|webhook_outgoing|ai_task`)
- `description` (string)
- `input.schema`
- `output.schema`
- if `kind=http`: `route.method`, `route.path`, and `auth`
- required sections even when mostly empty: `database`, `cache`, `events`, `jobs`, `tests`, `llm`

## Contract expectations
- input/output schemas are JSON Schema (draft 2020-12 style)
- query names in `feature.yaml` must exist in `queries.sql`
- referenced permissions must exist in `permissions.yaml`
- emitted events should define schema
- jobs should define payload schema/retry/queue/timeout
- required tests from `tests.required` should exist in `tests/`

## Generated artifacts
`foundry compile graph` produces canonical artifacts under `app/.foundry/build` and mirrors runtime projections to `app/generated`:
- `app/.foundry/build/graph/app_graph.json`
- `app/.foundry/build/graph/app_graph.php`
- `app/.foundry/build/projections/routes_index.php`
- `app/.foundry/build/projections/feature_index.php`
- `app/.foundry/build/projections/schema_index.php`
- `app/.foundry/build/projections/permission_index.php`
- `app/.foundry/build/projections/event_index.php`
- `app/.foundry/build/projections/job_index.php`
- `app/.foundry/build/projections/cache_index.php`
- `app/.foundry/build/projections/scheduler_index.php`
- `app/.foundry/build/projections/webhook_index.php`
- `app/.foundry/build/projections/query_index.php`
- `app/.foundry/build/manifests/compile_manifest.json`
- `app/.foundry/build/manifests/integrity_hashes.json`
- `app/.foundry/build/diagnostics/latest.json`

Compatibility mirrors in `app/generated`:
- `app/generated/routes.php`
- `app/generated/feature_index.php`
- `app/generated/schema_index.php`
- `app/generated/permission_index.php`
- `app/generated/event_index.php`
- `app/generated/job_index.php`
- `app/generated/cache_index.php`
- `app/generated/scheduler_index.php`
- `app/generated/webhook_index.php`
