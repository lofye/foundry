# Foundry Framework

Foundry is a production-minded, explicit, deterministic, LLM-first PHP framework for building feature-local web apps.
Visit [FoundryFramework.org](https://foundryframework.org) for extensive documentation.

Core Foundry remains MIT-licensed and fully usable without restriction.
Explain, generate, diagnostics, trace analysis, and graph diffing remain available without a license.
The monetization system is opt-in, local-first, and isolated from core compile, inspect, verify, scaffold, and runtime flows.

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

## Start Here

New to Foundry?

- Scaffold an app with `foundry new my-foundry-app --starter=standard --json`
- Use `foundry help inspect` and `foundry help verify` to discover the safest first commands
- Read `docs/quick-tour.md` and `docs/example-applications.md`
- Start with `examples/hello-world`

Contributing to the framework itself?

- Start with `docs/contributor-portal.md`
- Read `docs/architecture/architecture-overview.md`
- Use `php bin/foundry help inspect` and `php bin/foundry inspect cli-surface --json`

Initial Prompt: Derek Martin
Architect: ChatGPT-5.3
Engineer: GPT-5.3-Codex (Extra High)
License: MIT.

## Licensing

Foundry is fully usable without a license.
Licenses are retained for future identity and service access, and licensing remains local-first:

- core compile, inspect, verify, scaffold, runtime, and prompt flows remain available without a license
- `doctor --deep`, `explain`, `diff`, `trace`, and `generate "<prompt>"` remain available without a license
- licensing is stored locally at `~/.foundry/license.json` by default and may also be supplied through `FOUNDRY_LICENSE_KEY`
- license state is intended for future identity and service participation such as marketplace access
- `generate` works in deterministic mode without any provider and otherwise uses whatever local/remote provider you configure in `config/ai.php`
- `explain` derives architecture explanations from the compiled graph, projections, diagnostics, and docs metadata, not from an LLM
- usage tracking stays off unless you explicitly opt in with `FOUNDRY_USAGE_TRACKING=1`
- no background network calls are performed
- optional remote validation runs only during `license activate` when `FOUNDRY_LICENSE_VALIDATION_URL` is configured

Manage licensing locally:

```bash
foundry license activate --key=YOUR_KEY
foundry license status --json
foundry license deactivate --json
foundry features --json
```

Core commands do not depend on license state.

## Packs and Hosted Registry

Foundry supports deterministic local packs and an optional read-only hosted registry.

- pack sources must include a `foundry.json` manifest with `name`, `version`, `description`, `entry`, `capabilities`, `checksum`, and `signature`
- installed files are copied into `.foundry/packs/{vendor}/{pack}/{version}/` and remain immutable once installed
- active versions are tracked in `.foundry/packs/installed.json`
- graph boot loads only active packs, with deterministic ordering by pack name then active version
- activation fails explicitly when a pack introduces command collisions, schema collisions, or duplicate graph node ids
- hosted discovery reads a public JSON index from `FOUNDRY_PACK_REGISTRY_URL` or `https://foundryframework.org/packs`
- hosted rows include `download_url`, `checksum`, `signature`, and `verified`
- hosted downloads are metadata-only and must provide HTTPS `.zip` archives with `foundry.json` and `src/` at the root; installation still reuses the same local pack pipeline after extraction

Manage packs with:

```bash
foundry pack search blog --json
foundry pack install foundry/blog --json
foundry pack install foundry/blog@1.2.0 --json
foundry pack install ../packs/acme-blog --json
foundry pack list --json
foundry pack info acme/blog --json
foundry pack remove acme/blog --json
foundry inspect packs --json
```

The hosted registry remains optional. If it is unavailable, local pack installs still work unchanged. Pack entry classes must implement `Foundry\Packs\PackServiceProvider` and register behavior explicitly through `Foundry\Packs\PackContext`.

## Runtime and Language
- PHP `^8.4`
- Composer-based

In installed Foundry apps, use the project-local `foundry` launcher from the app root. If your shell does not resolve current-directory executables, use `./foundry ...`. In this framework repository, continue to use `php bin/foundry ...`.

## Install And First Run (Packagist)
```bash
composer require lofye/foundry-framework
foundry new my-foundry-app --starter=standard --json
cd my-foundry-app
composer install

foundry help inspect
foundry help verify
foundry compile graph --json
foundry inspect graph --json
foundry inspect pipeline --json
foundry doctor --json
foundry verify graph --json
foundry verify pipeline --json
foundry verify contracts --json
php -S 127.0.0.1:8000 public/index.php
```

If you are already inside an empty project directory, scaffold into the current folder instead:

```bash
mkdir my-foundry-app
cd my-foundry-app
composer require lofye/foundry-framework
foundry new . --starter=standard --name=acme/my-foundry-app --json
composer install
```

## Upgrade Foundry in an App
```bash
composer update lofye/foundry-framework
foundry compile graph --json
foundry verify graph --json
foundry verify contracts --json
```

## Core Workflow for LLMs
If you are just learning Foundry, stop after the first-run loop above and start with `examples/hello-world`. Use this fuller edit loop once you are actively changing features.

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
.foundry/
  packs/
    installed.json
    <vendor>/
      <pack>/
        <version>/
          foundry.json
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
- `.foundry/packs/installed.json` is explicit pack activation state when local packs are in use.
- `.foundry/cache/registry.json` is optional cached hosted pack registry metadata fetched from the public `/packs` endpoint.
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
foundry inspect cli-surface --json
foundry inspect api-surface --php=Foundry\\Feature\\FeatureAction --json
foundry verify cli-surface --json
```

Policy details live in `docs/public-api-policy.md` and are also emitted into generated docs as `docs/generated/api-surface.md` and `docs/generated/cli-reference.md`.

## Documentation Boundary
Canonical framework docs are authored in `docs/` in this repository.
The website repo imports that authored content plus generated reference material and is the canonical renderer/publisher of public docs and version snapshots.
Framework contributors should start with `docs/contributor-portal.md` for architecture, extension, workflow, and checklist guidance.
The canonical high-level architecture overview now lives in `docs/architecture/architecture-overview.md`; the root `ARCHITECTURE.md` file is only a pointer.

Refresh framework-side generated reference source files with:

```bash
php bin/foundry generate docs --format=markdown --json
php bin/foundry generate docs --format=html --json
```

Legacy local preview only:

```bash
php scripts/build-docs.php
```

That helper remains deprecated for framework-local preview output under `public/docs`. Do not use it as the primary publishing path.

## CLI Surface
All inspection, verification, and planning commands support `--json`.
The authoritative CLI catalog is derived from `ApiSurfaceRegistry` and exposed through:

```bash
foundry help --json
foundry help inspect
foundry help verify
foundry help generate
foundry inspect cli-surface --json
foundry verify cli-surface --json
```

The examples below are representative entry points; the generated `docs/generated/cli-reference.md` remains the exhaustive source-of-truth list.

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
foundry doctor --cli --json
foundry doctor --deep --json
```

Core capabilities and license services:

- Core: `compile`, `inspect`, `verify`, `doctor`, `doctor --deep`, `prompt`, `explain <target>`, `diff`, `trace [<target>]`, `generate "<prompt>"`, scaffold generators, runtime commands
- License services: `license status`, `license activate`, `license deactivate`, `features`

Licensed command surface:

```bash
foundry license activate --key=YOUR_KEY
foundry license status --json
foundry license deactivate --json
foundry features --json
foundry explain publish_post --json
foundry explain publish_post --deep
foundry explain route:POST /posts --markdown
foundry explain route:POST /posts --neighbors
foundry explain pack:foundry/blog --json
foundry diff --json
foundry trace publish_post --json
foundry generate "add bookmark support" --deterministic --dry-run --json
foundry generate "add bookmark support" --provider=static --model=fixture-model --dry-run --json
```

`explain` supports typed selectors such as `feature:publish_post`, `route:POST /posts`, `command:doctor`, `event:post.created`, `workflow:editorial`, `extension:core`, and `pack:foundry/blog`.
Default text output starts with `Subject` and `Summary`, then renders canonical sections such as `Responsibilities`, `Execution Flow`, `Depends On`, `Emits`, `Triggers`, `Permissions`, `Schema Interaction`, `Graph Relationships`, `Related Commands`, `Related Docs`, `Diagnostics`, and `Suggested Fixes` when present. Extra sections such as `Impact` render afterward through the assembler-owned `sectionOrder`.
`--deep` expands the same structure with detailed flow stages and expanded graph relationships instead of switching to a different format.
`--json` returns a deliberate machine-readable contract with canonical `graph`, `execution`, `guards`, `events`, `schemas`, `docs`, `impact`, `commands`, and `extensions` domains, while preserving the legacy `executionFlow`, `relationships`, `relatedCommands`, `relatedDocs`, `diagnostics`, `suggestedFixes`, `sections`, and `sectionOrder` keys for compatibility.
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

Representative inspect commands:
```bash
foundry help --json
foundry help inspect
foundry help verify
foundry help generate
foundry help inspect graph --json
foundry help inspect cli-surface --json
foundry inspect graph --json
foundry inspect cli-surface --json
foundry inspect build --json
foundry inspect node <node-id> --json
foundry inspect dependencies <feature|node-id> --json
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
```

Representative generate commands:
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

Representative export commands:
```bash
foundry export openapi --format=json --json
foundry export openapi --format=yaml --json
```

Representative verify commands:
```bash
foundry verify feature <feature> --json
foundry verify graph --json
foundry verify pipeline --json
foundry verify cli-surface --json
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

Representative runtime / planning commands:
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

canonical:
- `examples/hello-world`
- `examples/blog-api`
- `examples/workflow-events`

reference:
- `examples/extensions-migrations`
- `examples/reference-blog`

framework:
- `examples/compiler-core`
- `examples/architecture-tools`
- `examples/execution-pipeline`
- `examples/app-scaffolding`
- `examples/integration-tooling`

The `canonical` examples keep authored source only and do not commit `app/generated/*`; compile them after copying into an app.

## Additional Docs
- `docs/architecture/architecture-overview.md`
- `ARCHITECTURE.md` (repo pointer)
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
