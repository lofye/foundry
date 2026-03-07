# Foundry

Foundry is a production-minded, explicit, deterministic, LLM-first PHP framework for building feature-local web apps.

It is optimized for:
- explicit contracts
- deterministic generation
- machine-readable inspection
- small safe edit surfaces
- strong verification and testing

Initial Prompt: Derek Martin
Architect: ChatGPT-5.3
Engineer: GPT-5.3-Codex (Extra High)
License: MIT.

## Runtime and Language
- PHP `^8.5`
- Composer-based

## Install and Run (Packagist)
```bash
# Create a new project folder
mkdir my-foundry-app
cd my-foundry-app

# Install Foundry
composer require lofye/foundry:^0.3

# Initialize a new Foundry app in this folder
php vendor/bin/foundry init app . --name=acme/my-foundry-app

# Install project dependencies
composer install

# Generate indexes and verify contracts
php vendor/bin/foundry generate indexes --json
php vendor/bin/foundry verify contracts --json
php -S 127.0.0.1:8000 app/platform/public/index.php
```

## Upgrade Foundry in an App
```bash
composer update lofye/foundry
php vendor/bin/foundry generate indexes --json
php vendor/bin/foundry verify contracts --json
```

## Local MinIO (Fix + Verify)
Your MinIO install issue is typically a port conflict on `127.0.0.1:9000`.

Check what owns the ports:
```bash
lsof -nP -iTCP:9000 -sTCP:LISTEN
lsof -nP -iTCP:9001 -sTCP:LISTEN
```

Option A: keep defaults and stop the conflicting process.

Option B: run MinIO on alternate ports (recommended if 9000 is already used):
```bash
mkdir -p "$HOME/minio-data"
export MINIO_ROOT_USER="foundry"
export MINIO_ROOT_PASSWORD="foundry-dev-secret"
minio server "$HOME/minio-data" --address ":9100" --console-address ":9101"
```

Configure `mc` and create a bucket:
```bash
mc alias set foundry http://127.0.0.1:9100 foundry foundry-dev-secret
mc mb --ignore-existing foundry/foundry-dev
mc ls foundry
```

Health check:
```bash
curl -sS http://127.0.0.1:9100/minio/health/live
```

Notes:
- `/home/shared` is a Linux path; on macOS use an existing directory like `$HOME/minio-data`.
- avoid default credentials (`minioadmin:minioadmin`) for persistent local setups.

## Core Workflow for LLMs
Use this loop for every change:
1. Inspect current reality.
2. Edit the minimum feature-local files.
3. Regenerate indexes/context.
4. Verify contracts/rules.
5. Run tests.

Recommended command sequence:
```bash
php vendor/bin/foundry inspect feature <feature> --json
php vendor/bin/foundry inspect context <feature> --json
php vendor/bin/foundry generate indexes --json
php vendor/bin/foundry generate context <feature> --json
php vendor/bin/foundry verify feature <feature> --json
php vendor/bin/foundry verify contracts --json
php vendor/bin/foundry verify auth --json
php vendor/bin/foundry verify cache --json
php vendor/bin/foundry verify events --json
php vendor/bin/foundry verify jobs --json
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
- action implementation (`action.php` implementing `Foundry\Feature\FeatureAction`)
- input/output schemas
- context manifest
- tests declared in `feature.yaml`

Optional feature-local files:
- `queries.sql`, `permissions.yaml`, `cache.yaml`, `events.yaml`, `jobs.yaml`, `prompts.md`

## CLI Surface
All inspection, verification, and planning commands support `--json`.

Inspect:
```bash
php vendor/bin/foundry inspect feature <feature> --json
php vendor/bin/foundry inspect route <METHOD> <PATH> --json
php vendor/bin/foundry inspect auth <feature> --json
php vendor/bin/foundry inspect cache <feature> --json
php vendor/bin/foundry inspect events <feature> --json
php vendor/bin/foundry inspect jobs <feature> --json
php vendor/bin/foundry inspect context <feature> --json
php vendor/bin/foundry inspect dependencies <feature> --json
```

Generate:
```bash
php vendor/bin/foundry generate feature <spec.yaml> --json
php vendor/bin/foundry generate indexes --json
php vendor/bin/foundry generate tests <feature> --json
php vendor/bin/foundry generate migration <spec.yaml> --json
php vendor/bin/foundry generate context <feature> --json
```

Verify:
```bash
php vendor/bin/foundry verify feature <feature> --json
php vendor/bin/foundry verify contracts --json
php vendor/bin/foundry verify auth --json
php vendor/bin/foundry verify cache --json
php vendor/bin/foundry verify events --json
php vendor/bin/foundry verify jobs --json
php vendor/bin/foundry verify migrations --json
```

Runtime / planning:
```bash
php vendor/bin/foundry init app <path> [--name=vendor/app] [--version=^0.1] [--force]
php vendor/bin/foundry serve
php vendor/bin/foundry queue:work
php vendor/bin/foundry queue:inspect --json
php vendor/bin/foundry schedule:run --json
php vendor/bin/foundry trace:tail --json
php vendor/bin/foundry affected-files <feature> --json
php vendor/bin/foundry impacted-features <permission|event:<name>|cache:<key>> --json
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

Optional local integration targets:
- Redis queue integration tests run when `ext-redis` is loaded and Redis is reachable on `127.0.0.1:6379`.
- PostgreSQL integration tests run when `pdo_pgsql` is loaded and Postgres is reachable.
- MinIO storage integrations can use `Foundry\Storage\MinioStorageDriver` with an injected client, or with `aws/aws-sdk-php`.
- MinIO integration test env overrides:
  `FOUNDRY_TEST_MINIO_ENDPOINT`, `FOUNDRY_TEST_MINIO_ACCESS_KEY`,
  `FOUNDRY_TEST_MINIO_SECRET_KEY`, `FOUNDRY_TEST_MINIO_BUCKET`,
  `FOUNDRY_TEST_MINIO_REGION`.
- PostgreSQL DSN/user/pass can be overridden with:
  `FOUNDRY_TEST_PG_DSN`, `FOUNDRY_TEST_PG_USER`, `FOUNDRY_TEST_PG_PASS`.
- If `postgresql@17` is keg-only on Homebrew, use:
  `/opt/homebrew/opt/postgresql@17/bin/psql`
  and optionally add it to `PATH`.

Coverage note:
- code coverage output requires a coverage driver (`xdebug` or `pcov`).
- if not installed, tests still run but coverage metrics are unavailable.
- when Xdebug is installed but not auto-loaded in CLI, run coverage with:
  `php -dzend_extension=/path/to/xdebug.so -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text`

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
