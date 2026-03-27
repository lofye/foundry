# Foundry Framework

Foundry is a production-minded, explicit, deterministic, LLM-first PHP framework for building feature-local web apps.
Visit [FoundryFramework.org](https://foundryframework.org) for extensive documentation.

Core Foundry remains MIT-licensed and fully usable without restriction.
Foundry Pro is an optional, additive layer for deeper diagnostics, architecture understanding, trace analysis, graph diffing, and AI-assisted workflows.

It is optimized for:
- explicit contracts
- deterministic generation
- machine-readable inspection
- small safe edit surfaces
- strong verification and testing

Foundry now includes a semantic compiler core:
- source files are compiled into a canonical application graph
- runtime indexes are projections of that graph
- verification and impact analysis operate over compiled graph state

Initial Prompt: Derek Martin
Architect: ChatGPT-5.3
Engineer: GPT-5.3-Codex (Extra High)
License: MIT.

## Foundry Pro

Foundry Pro is optional and local-first:

- core compile, inspect, verify, scaffold, runtime, and prompt flows remain available without Pro
- Pro adds `doctor --deep`, `explain`, `diff`, `trace`, and `generate "<prompt>"`
- Pro does not require SaaS connectivity, telemetry, or runtime calls to external services
- Pro licensing is stored locally at `~/.foundry/license.json` by default
- `generate` works in deterministic mode without any provider and otherwise uses whatever local/remote provider you configure in `config/ai.php`
- `explain` derives architecture explanations from the compiled graph, projections, diagnostics, and docs metadata, not from an LLM

Enable Pro locally:

```bash
foundry pro enable <license-key>
foundry pro status --json
```

Without a valid license, Pro commands stay visible in help, return a clear message, and exit non-zero without affecting core framework behavior.

## Runtime and Language
- PHP `^8.4`
- Composer-based

In installed Foundry apps, use the project-local `foundry` launcher from the app root. If your shell does not resolve current-directory executables, use `./foundry ...`. In this framework repository, continue to use `php bin/foundry ...`.

## Install and Run (Packagist)
```bash
# Create a new project folder
mkdir my-foundry-app
cd my-foundry-app

# Install Foundry
composer require lofye/foundry-framework

# Initialize a new Foundry app in this folder
foundry new --starter=standard --name=acme/my-foundry-app

# Install project dependencies
composer install

# Compile, inspect, and verify contracts
foundry compile graph --json
foundry inspect graph --json
foundry inspect pipeline --json
foundry doctor --json
foundry verify graph --json
foundry verify pipeline --json
foundry verify contracts --json
php -S 127.0.0.1:8000 public/index.php
```

From a Foundry-enabled parent directory, you can also scaffold into a child folder directly:

```bash
composer require lofye/foundry-framework
foundry new website --starter=standard --json
cd website
composer install
```

## Upgrade Foundry in an App
```bash
composer update lofye/foundry-framework
foundry compile graph --json
foundry verify graph --json
foundry verify contracts --json
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
3. Compile graph.
4. Inspect diagnostics and impact.
5. Verify graph/contracts/rules.
6. Run tests.

Recommended command sequence:
```bash
foundry inspect feature <feature> --json
foundry inspect context <feature> --json
foundry compile graph --json
foundry inspect graph --json
foundry inspect impact --file=app/features/<feature>/feature.yaml --json
foundry verify graph --json
foundry generate context <feature> --json
foundry verify feature <feature> --json
foundry verify contracts --json
foundry verify auth --json
foundry verify cache --json
foundry verify events --json
foundry verify jobs --json
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
      queries.sql
      permissions.yaml
      cache.yaml
      events.yaml
      jobs.yaml
      prompts.md      
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
  .foundry/
    build/
      graph/
      projections/
      manifests/
      diagnostics/
bootstrap/
  app.php
  providers.php
config/
  app.php
  auth.php
  database.php
  cache.php
  queue.php
  storage.php
  ai.php
database/
  migrations/
lang/
  en/
public/
  index.php
storage/
  files/
  logs/
  tmp/
```

Rules:
- `app/features/*` is source-of-truth behavior.
- `app/.foundry/build/*` is canonical compiled output.
- `app/generated/*` remains a compatibility mirror of runtime projections.
- hot-path runtime reads generated projections (no folder scanning in request path).

## Feature Contract
Each feature must define:
- manifest (`feature.yaml`)
- action implementation (`action.php` implementing `Foundry\Feature\FeatureAction`)
- input/output schemas
- context manifest
- tests declared in `feature.yaml`

Optional feature-local files:
- `queries.sql`, `permissions.yaml`, `cache.yaml`, `events.yaml`, `jobs.yaml`, `prompts.md`

## API Stability
Foundry now classifies framework surface area explicitly:
- `public_api`: safe for apps and automation to depend on
- `extension_api`: safe for extensions to implement against
- `experimental_api`: available, but still allowed to change in minor releases
- `internal_api`: implementation detail, not a supported dependency

Stable CLI commands and the API surface registry are inspectable:
```bash
foundry help --json
foundry help compile graph --json
foundry inspect api-surface --json
foundry inspect api-surface --php=Foundry\\Feature\\FeatureAction --json
```

Policy details live in `docs/public-api-policy.md` and are also emitted into generated docs as `docs/generated/api-surface.md` and `docs/generated/cli-reference.md`.

## Documentation Site
Framework documentation is built as a static site from curated pages plus generated graph/schema/CLI reference content:

```bash
php scripts/build-docs.php
```

The build compiles the root app graph, merges `docs/*.md` with generated reference pages, and writes the current site plus versioned snapshots to `public/docs`.

## CLI Surface
All inspection, verification, and planning commands support `--json`.

Compile:
```bash
foundry compile graph --json
foundry compile graph --feature=<feature> --json
foundry compile graph --changed-only --json
```

Architecture analysis:
```bash
foundry doctor --json
foundry doctor --strict --json
foundry doctor --feature=<feature> --json
foundry doctor --deep --json
```

Core vs Pro:

- Free/core: `compile`, `inspect`, `verify`, `doctor`, `prompt`, scaffold generators, runtime commands
- Pro: `doctor --deep`, `explain <target>`, `diff`, `trace [<target>]`, `generate "<prompt>"`, `pro enable`, `pro status`

Pro command surface:

```bash
foundry pro enable <license-key>
foundry pro status --json
foundry explain publish_post --json
foundry explain publish_post --deep
foundry explain route:POST /posts --markdown
foundry explain route:POST /posts --neighbors
foundry diff --json
foundry trace publish_post --json
foundry generate "add bookmark support" --deterministic --dry-run --json
foundry generate "add bookmark support" --provider=static --model=fixture-model --dry-run --json
```

`explain` supports typed selectors such as `feature:publish_post`, `route:POST /posts`, `command:doctor`, `event:post.created`, `workflow:editorial`, and `extension:core`.
Default text output starts with `Subject` and `Summary`, then renders canonical sections such as `Responsibilities`, `Execution Flow`, `Depends On`, `Emits`, `Triggers`, `Permissions`, `Schema Interaction`, `Graph Relationships`, `Related Commands`, `Related Docs`, `Diagnostics`, and `Suggested Fixes` when present. Extra sections such as `Impact` render afterward through the assembler-owned `sectionOrder`.
`--deep` expands the same structure with detailed flow stages and expanded graph relationships instead of switching to a different format.
`--json` returns a deliberate machine-readable contract with `executionFlow`, `relationships`, `relatedCommands`, `relatedDocs`, `diagnostics`, `suggestedFixes`, `sections`, and `sectionOrder` keys rather than renderer-specific text fragments.
Extensions can enrich explain output deterministically by implementing `Foundry\Explain\Contributors\ExplainContributorInterface` and returning `Foundry\Explain\Contributors\ExplainContribution` entries that the registry merges before rendering. Contributor sections are normalized through `Foundry\Explain\ExplainSection`.

Provider-backed generation is still optional. If no provider is configured, `generate` exits non-zero with a clear message and suggests `--deterministic`.

Minimal AI provider config:

```php
<?php
declare(strict_types=1);

return [
    'default' => 'static',
    'providers' => [
        'static' => [
            'driver' => 'static',
            'model' => 'fixture-model',
            'parsed' => [
                'feature' => [
                    'feature' => 'favorite_post',
                    'route' => ['method' => 'POST', 'path' => '/posts/{id}/favorite'],
                ],
                'explanation' => 'Provider-authored generation plan.',
            ],
        ],
    ],
];
```

Graph inspection and export:
```bash
foundry inspect graph --json
foundry inspect graph --command="POST /posts" --format=dot --json
foundry graph inspect --workflow=posts --json
foundry graph visualize --pipeline --feature=<feature> --format=mermaid --json
foundry export graph --extension=core --format=json --json
```

AI prompt loop:
```bash
foundry prompt "add bookmark endpoint for posts" --json
foundry prompt "add bookmark endpoint for posts" --feature-context --dry-run --json
foundry preview notification <name> --json
foundry upgrade-check --json
foundry upgrade-check --target=1.0.0 --json
```

Inspect:
```bash
foundry help --json
foundry help inspect graph --json
foundry inspect graph --json
foundry inspect build --json
foundry inspect node <node-id> --json
foundry inspect dependencies <node-id> --json
foundry inspect dependents <node-id> --json
foundry inspect pipeline --json
foundry inspect execution-plan <feature|route> --json
foundry inspect guards --json
foundry inspect guards <feature> --json
foundry inspect interceptors --json
foundry inspect interceptors --stage=<stage> --json
foundry inspect impact <node-id> --json
foundry inspect impact --file=<path> --json
foundry inspect affected-tests <node-id> --json
foundry inspect affected-features <node-id> --json
foundry inspect extensions --json
foundry inspect extension <name> --json
foundry inspect packs --json
foundry inspect pack <name> --json
foundry inspect compatibility --json
foundry inspect migrations --json
foundry inspect definition-format <name> --json
foundry inspect api-surface --json
foundry inspect resource <name> --json
foundry inspect notification <name> --json
foundry inspect api <name> --json
foundry inspect feature <feature> --json
foundry inspect route <METHOD> <PATH> --json
foundry inspect auth <feature> --json
foundry inspect cache <feature> --json
foundry inspect events <feature> --json
foundry inspect jobs <feature> --json
foundry inspect context <feature> --json
foundry inspect dependencies <feature> --json
```

Generate:
```bash
foundry generate feature <definition.yaml> --json
foundry generate starter server-rendered --json
foundry generate starter api --json
foundry generate resource <name> --definition=<file> --json
foundry generate admin-resource <name> --json
foundry generate uploads avatar --json
foundry generate uploads attachments --json
foundry generate notification <name> --json
foundry generate api-resource <name> --definition=<file> --json
foundry generate docs --format=markdown --json
foundry generate docs --format=html --json
foundry generate indexes --json
foundry generate tests <feature> --json
foundry generate tests <target> --mode=deep --json
foundry generate tests --all-missing --mode=deep --json
foundry generate migration <definition.yaml> --json
foundry generate context <feature> --json
```

Export:
```bash
foundry export openapi --format=json --json
foundry export openapi --format=yaml --json
```

Verify:
```bash
foundry verify feature <feature> --json
foundry verify graph --json
foundry verify pipeline --json
foundry verify extensions --json
foundry verify compatibility --json
foundry verify contracts --json
foundry verify auth --json
foundry verify cache --json
foundry verify events --json
foundry verify jobs --json
foundry verify migrations --json
foundry verify resource <name> --json
foundry verify notifications --json
foundry verify api --json
```

Runtime / planning:
```bash
foundry new [path] [--starter=minimal|standard|api-first] [--name=vendor/app] [--version=^0.1] [--force]
foundry init app <path> [--starter=minimal|standard|api-first] [--name=vendor/app] [--version=^0.1] [--force]
foundry serve
foundry queue:work
foundry queue:inspect --json
foundry schedule:run --json
foundry trace:tail --json
foundry affected-files <feature> --json
foundry impacted-features <permission|event:<name>|cache:<key>> --json
foundry migrate definitions --dry-run --json
foundry migrate definitions --path=<path> --dry-run --json
foundry migrate definitions --write --json
foundry codemod run <name> --dry-run --json
foundry codemod run <name> --write --json
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
Start with `docs/example-applications.md` or `examples/README.md`.

Official example set:
- `examples/hello-world`
- `examples/blog-api`
- `examples/extensions-migrations`
- `examples/workflow-events`
- `examples/reference-blog`

Supplemental app examples:
- `examples/dashboard`
- `examples/ai-pipeline`

Framework/tooling examples:
- `examples/compiler-core`
- `examples/architecture-tools`
- `examples/execution-pipeline`
- `examples/app-scaffolding`
- `examples/integration-tooling`

Thresholds should be treated as the richer real-app reference; the smaller examples stay focused on one teachable pattern at a time.

## Additional Docs
- `ARCHITECTURE.md`
- `FEATURE_DEFINITION.md`
- `BENCHMARK_NOTES.md`
- `docs/semantic-compiler.md`
- `docs/extensions-and-migrations.md`
- `docs/extension-author-guide.md`
- `docs/upgrade-safety.md`
- `docs/architecture-tools.md`
- `docs/execution-pipeline.md`
- `docs/app-scaffolding.md`
- `docs/example-applications.md`
- `docs/public-api-policy.md`
- `docs/api-notifications-docs.md`
- `docs/contributor-vocabulary.md`
