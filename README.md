# Foundry

Foundry is a production-minded, explicit, deterministic, LLM-first PHP framework for building feature-local web apps.

It is designed to help humans and LLMs work on the same codebase without relying on hidden framework behavior, sprawling edit surfaces, or lucky guesses.

## What Foundry optimizes for

- explicit contracts
- deterministic generation
- machine-readable inspection
- small, safe edit surfaces
- generated runtime indexes
- strong verification and testing
- feature-local application structure

## What “LLM-first” means here

Foundry is not trying to replace developers.

It is trying to make AI-assisted development more factual.

Most frameworks assume the developer already knows where everything is and how the conventions fit together. Foundry assumes the codebase should explain itself to both humans and machines.

That is why Foundry uses:

- feature-local folders instead of scattering one behavior across the codebase
- generated indexes for runtime metadata instead of hot-path folder scanning
- JSON-schema contracts at boundaries
- explicit inspect / generate / verify commands with stable JSON output
- verification as part of the normal development loop

## Requirements

- PHP `^8.5` according to the repository README
- Composer

> Note: Packagist metadata currently lists `php: ^8.4`, while the repository README says `^8.5`. Pick one target and keep both sources aligned before publishing broadly.

## Install Foundry in a new app

```bash
mkdir my-foundry-app
cd my-foundry-app
composer require lofye/foundry:^0.2
php vendor/bin/foundry init app . --name=acme/my-foundry-app
composer install
php vendor/bin/foundry generate indexes --json
php vendor/bin/foundry verify contracts --json
```

Then serve the app locally:

```bash
php -S 127.0.0.1:8000 app/platform/public/index.php
```

Or use the Foundry runtime command if that fits your workflow:

```bash
php vendor/bin/foundry serve
```

## Upgrade Foundry inside an app

```bash
composer update lofye/foundry
php vendor/bin/foundry generate indexes --json
php vendor/bin/foundry verify contracts --json
vendor/bin/phpunit
```

## The shape of a Foundry app

A Foundry app has three main zones:

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
      queries.sql
      permissions.yaml
      cache.yaml
      events.yaml
      jobs.yaml
      prompts.md
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

### What each zone means

#### `app/features/`
This is the source of truth for application behavior.

If you are adding or modifying a feature, this is where the real intent lives.

#### `app/generated/`
This is deterministic runtime metadata generated from the feature contracts.

Production reads these indexes on the hot path.

#### `app/platform/`
This is low-level application wiring: bootstrap, config, migrations, and the public entrypoint.

It is infrastructure, not feature logic.

## The feature contract

In Foundry, the feature is the minimum unit of behavior.

Every feature must include:

- `feature.yaml`
- `action.php`
- `input.schema.json`
- `output.schema.json`
- `context.manifest.json`
- `tests/`

Strongly encouraged files:

- `queries.sql`
- `permissions.yaml`
- `cache.yaml`
- `events.yaml`
- `jobs.yaml`
- `prompts.md`

This structure is deliberate. It gives humans and LLMs a small local surface for understanding and editing one bounded behavior.

## The development loop

Foundry works best when you follow the same loop for every change.

### 1. Inspect current reality

Start by asking Foundry what exists.

```bash
php vendor/bin/foundry inspect feature <feature> --json
php vendor/bin/foundry inspect context <feature> --json
php vendor/bin/foundry inspect auth <feature> --json
php vendor/bin/foundry inspect dependencies <feature> --json
```

Do not begin with guesses when the framework can answer the question.

### 2. Edit the minimum feature-local files

Change only the files that represent the source-of-truth behavior for the feature.

That usually means working inside:

```text
app/features/<feature>/
```

### 3. Regenerate runtime metadata

After changing feature contracts, regenerate the indexes and context manifests that the runtime and future LLM runs depend on.

```bash
php vendor/bin/foundry generate indexes --json
php vendor/bin/foundry generate context <feature> --json
```

### 4. Verify the rules

Run the verifiers.

```bash
php vendor/bin/foundry verify feature <feature> --json
php vendor/bin/foundry verify contracts --json
php vendor/bin/foundry verify auth --json
php vendor/bin/foundry verify cache --json
php vendor/bin/foundry verify events --json
php vendor/bin/foundry verify jobs --json
php vendor/bin/foundry verify migrations --json
```

### 5. Run tests

```bash
vendor/bin/phpunit
```

That loop is the heart of Foundry:

**inspect → edit → regenerate → verify → test**

## CLI overview

All inspection, verification, and planning commands support `--json`.

### Inspect

```bash
php vendor/bin/foundry inspect feature <feature> --json
php vendor/bin/foundry inspect route <method> <path> --json
php vendor/bin/foundry inspect auth <feature> --json
php vendor/bin/foundry inspect cache <feature> --json
php vendor/bin/foundry inspect events <feature> --json
php vendor/bin/foundry inspect jobs <feature> --json
php vendor/bin/foundry inspect context <feature> --json
php vendor/bin/foundry inspect dependencies <feature> --json
```

### Generate

```bash
php vendor/bin/foundry generate feature <spec-file> --json
php vendor/bin/foundry generate indexes --json
php vendor/bin/foundry generate tests <feature> --json
php vendor/bin/foundry generate migration <spec-file> --json
php vendor/bin/foundry generate context <feature> --json
```

### Verify

```bash
php vendor/bin/foundry verify feature <feature> --json
php vendor/bin/foundry verify contracts --json
php vendor/bin/foundry verify auth --json
php vendor/bin/foundry verify cache --json
php vendor/bin/foundry verify events --json
php vendor/bin/foundry verify jobs --json
php vendor/bin/foundry verify migrations --json
```

### Runtime and planning

```bash
php vendor/bin/foundry init app . --name=acme/my-foundry-app
php vendor/bin/foundry serve
php vendor/bin/foundry queue:work
php vendor/bin/foundry queue:inspect --json
php vendor/bin/foundry schedule:run --json
php vendor/bin/foundry trace:tail --json
php vendor/bin/foundry affected-files <feature> --json
php vendor/bin/foundry impacted-features <target> --json
```

## Why the code is organized this way

### 1. To reduce ambiguity for LLMs

Feature-local architecture means the relevant files for one behavior live close together.

That reduces token cost and lowers the odds of accidental edits far away from the requested change.

### 2. To make runtime behavior derived instead of inferred

Generated indexes let Foundry derive runtime metadata from source contracts.

That keeps production fast and predictable.

### 3. To make verification possible

If routes, schemas, cache rules, jobs, events, and permissions are explicit, Foundry can verify them.

If those things are hidden in conventions and side effects, the framework has less to prove against.

### 4. To help humans too

This architecture is not only for machines.

Humans get:

- more obvious edit boundaries
- easier review of AI-generated changes
- clearer runtime behavior
- better inspection and planning commands
- a more disciplined path from prompt to deployable code

## What happens when developers prompt an LLM to build with Foundry

A good Foundry workflow looks like this:

1. The developer asks the LLM to inspect a feature or route first.
2. The LLM uses Foundry CLI output to understand the current state.
3. The LLM edits only the local feature files that should change.
4. The developer or agent regenerates indexes and context.
5. Foundry verifies the contracts and dependencies.
6. The test suite confirms the change is coherent.

This keeps the conversation grounded in reality.

Instead of “please change the app somehow,” the process becomes:

- inspect the feature
- understand the boundaries
- change the minimum source-of-truth files
- regenerate what runtime needs
- verify what changed
- run tests

That is the point of Foundry.

## Testing

Foundry includes unit and integration coverage for:

- parsing, validation, generation, and verification
- CLI JSON command behavior
- HTTP feature execution pipeline
- DB query execution
- queue, event, cache, webhook, and AI subsystems
- example app structure checks

Run the test suite with:

```bash
vendor/bin/phpunit
```

### Optional integration targets

Foundry can also run deeper integration tests when the required extensions or services are available:

- Redis queue tests when `ext-redis` is loaded and Redis is reachable
- PostgreSQL tests when `pdo_pgsql` is loaded and Postgres is reachable
- MinIO / S3-like storage integrations when storage dependencies are configured

## Examples included

Foundry includes example applications:

- `examples/blog-api`
- `examples/dashboard`
- `examples/ai-pipeline`

These are useful when you want to see the feature-local structure in a more concrete setting.

## Additional docs

The repository also includes:

- `ARCHITECTURE.md`
- `FEATURE_SPEC.md`
- `BENCHMARK_NOTES.md`

## Closing thought

Foundry is trying to make AI-assisted development less mystical.

It does that by organizing applications around explicit feature contracts, deriving runtime metadata from those contracts, exposing machine-readable inspection commands, and treating verification as a normal part of development.

Or, put more bluntly: it is a framework for teams that want AI to help build software without letting the codebase turn into haunted spaghetti.
