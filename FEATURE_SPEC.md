# Feature Definition Spec (Forge v1)

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
`forge generate indexes` produces:
- `app/generated/routes.php`
- `app/generated/feature_index.php`
- `app/generated/schema_index.php`
- `app/generated/permission_index.php`
- `app/generated/event_index.php`
- `app/generated/job_index.php`
- `app/generated/cache_index.php`
- `app/generated/scheduler_index.php`
- `app/generated/webhook_index.php`
