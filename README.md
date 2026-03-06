# Forge

Forge is a production-minded, explicit, deterministic, LLM-first PHP framework for building feature-local web apps.

It is optimized for:
- explicit contracts
- deterministic generation
- machine-readable inspection
- small safe edit surfaces
- strong verification and testing

License: MIT.

## Runtime and Language
- PHP `^8.4` (code is written in a PHP 8.5-ready style)
- Composer-based

## Install and Run
```bash
composer install
php bin/forge generate indexes
php bin/forge verify contracts --json
vendor/bin/phpunit
```

## Core Workflow for LLMs
Use this loop for every change:
1. Inspect current reality.
2. Edit the minimum feature-local files.
3. Regenerate indexes/context.
4. Verify contracts/rules.
5. Run tests.

Recommended command sequence:
```bash
php bin/forge inspect feature <feature> --json
php bin/forge inspect context <feature> --json
php bin/forge generate indexes --json
php bin/forge generate context <feature> --json
php bin/forge verify feature <feature> --json
php bin/forge verify contracts --json
php bin/forge verify auth --json
php bin/forge verify cache --json
php bin/forge verify events --json
php bin/forge verify jobs --json
vendor/bin/phpunit
```

## App Structure
```text
app/
  features/
    <feature>/
      feature.yaml
      action.php
      input.schema.json
      output.schema.json
      context.manifest.json
      tests/
  generated/
    routes.php
    feature_index.php
    schema_index.php
    permission_index.php
    event_index.php
    job_index.php
    cache_index.php
    scheduler_index.php
    webhook_index.php
  platform/
    bootstrap/
    config/
    migrations/
    public/index.php
```

Rules:
- `app/features/*` is source-of-truth behavior.
- `app/generated/*` is regenerated, deterministic runtime metadata.
- hot-path runtime reads generated indexes (no folder scanning in request path).

## Feature Contract
Each feature must define:
- manifest (`feature.yaml`)
- action implementation (`action.php` implementing `Forge\Feature\FeatureAction`)
- input/output schemas
- context manifest
- tests declared in `feature.yaml`

Optional feature-local files:
- `queries.sql`, `permissions.yaml`, `cache.yaml`, `events.yaml`, `jobs.yaml`, `prompts.md`

## CLI Surface
All inspection, verification, and planning commands support `--json`.

Inspect:
```bash
php bin/forge inspect feature <feature> --json
php bin/forge inspect route <METHOD> <PATH> --json
php bin/forge inspect auth <feature> --json
php bin/forge inspect cache <feature> --json
php bin/forge inspect events <feature> --json
php bin/forge inspect jobs <feature> --json
php bin/forge inspect context <feature> --json
php bin/forge inspect dependencies <feature> --json
```

Generate:
```bash
php bin/forge generate feature <spec.yaml> --json
php bin/forge generate indexes --json
php bin/forge generate tests <feature> --json
php bin/forge generate migration <spec.yaml> --json
php bin/forge generate context <feature> --json
```

Verify:
```bash
php bin/forge verify feature <feature> --json
php bin/forge verify contracts --json
php bin/forge verify auth --json
php bin/forge verify cache --json
php bin/forge verify events --json
php bin/forge verify jobs --json
php bin/forge verify migrations --json
```

Runtime / planning:
```bash
php bin/forge serve
php bin/forge queue:work
php bin/forge queue:inspect --json
php bin/forge schedule:run --json
php bin/forge trace:tail --json
php bin/forge affected-files <feature> --json
php bin/forge impacted-features <permission|event:<name>|cache:<key>> --json
```

## Tests
Test suite includes unit and integration coverage for:
- parsing/validation/generation/verification
- CLI JSON command behavior
- HTTP feature execution pipeline
- DB query execution
- queue/event/cache/webhook/AI subsystems
- example app structure checks

Run:
```bash
vendor/bin/phpunit
```

Coverage note:
- code coverage output requires a coverage driver (`xdebug` or `pcov`).
- if not installed, tests still run but coverage metrics are unavailable.

## Examples
Included example apps:
- `examples/blog-api`
- `examples/dashboard`
- `examples/ai-pipeline`

Each example includes feature folders plus generated indexes.

## Additional Docs
- `ARCHITECTURE.md`
- `FEATURE_SPEC.md`
- `BENCHMARK_NOTES.md`
