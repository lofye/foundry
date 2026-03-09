# Foundry Semantic Compiler (Phase 0)

## What this adds
Foundry now has a compiler core that turns feature source files into a single canonical application graph.

The graph is the internal contract for:
- runtime projections/indexes
- verification
- inspection/impact analysis
- migration and codemod planning
- extension hooks for future capabilities

## Source-of-truth and graph model
Author these files as source of truth:
- `app/features/<feature>/feature.yaml`
- `input.schema.json` / `output.schema.json`
- `queries.sql`
- `permissions.yaml`
- `events.yaml`
- `jobs.yaml`
- `cache.yaml`
- `scheduler.yaml` (optional)
- `webhooks.yaml` (optional)
- `context.manifest.json` (optional but recommended)

The compiler normalizes these into typed IR nodes and edges.

Minimum core node types:
- `feature`, `route`, `schema`, `permission`, `query`
- `event`, `job`, `cache`, `scheduler`, `webhook`
- `test`, `context_manifest`, `auth`, `rate_limit`

## Compiler passes
The pipeline is explicit and ordered:
1. `discovery`
2. `normalize`
3. `link`
4. `validate`
5. `enrich`
6. `analyze`
7. `emit`

Each pass is independently testable and deterministic.

## Build artifact layout
Compiler outputs are emitted under:

```text
app/.foundry/build/
  graph/
    app_graph.json
    app_graph.php
  projections/
    routes_index.php
    feature_index.php
    schema_index.php
    permission_index.php
    event_index.php
    job_index.php
    cache_index.php
    scheduler_index.php
    webhook_index.php
    query_index.php
  manifests/
    compile_manifest.json
    integrity_hashes.json
  diagnostics/
    latest.json
```

Compatibility mirrors are still written to `app/generated/*` for runtime and existing tooling compatibility.

## Compile vs verify
- `compile` builds graph + projections + diagnostics + manifests.
- `verify` checks correctness/integrity of produced artifacts and domain contracts.

## CLI surface (Phase 0)
Compile:
```bash
php vendor/bin/foundry compile graph --json
php vendor/bin/foundry compile graph --feature=<feature> --json
php vendor/bin/foundry compile graph --changed-only --json
```

Inspect:
```bash
php vendor/bin/foundry inspect graph --json
php vendor/bin/foundry inspect build --json
php vendor/bin/foundry inspect node <node-id> --json
php vendor/bin/foundry inspect dependencies <node-id> --json
php vendor/bin/foundry inspect dependents <node-id> --json
php vendor/bin/foundry inspect impact <node-id> --json
php vendor/bin/foundry inspect impact --file=<path> --json
php vendor/bin/foundry inspect affected-tests <node-id> --json
php vendor/bin/foundry inspect affected-features <node-id> --json
php vendor/bin/foundry inspect extensions --json
php vendor/bin/foundry inspect migrations --json
```

Verify graph:
```bash
php vendor/bin/foundry verify graph --json
```

Migrate specs:
```bash
php vendor/bin/foundry migrate specs --dry-run --json
php vendor/bin/foundry migrate specs --write --json
```

## Diagnostics
Diagnostics are structured JSON records with:
- `code`
- `severity` (`error|warning|info`)
- `category`
- `message`
- `node_id`
- `source_path`
- `related_nodes`
- `suggested_fix` (when available)

Canonical artifact:
- `app/.foundry/build/diagnostics/latest.json`

## Incremental compile behavior
Foundry supports:
- full compile
- feature-targeted compile
- changed-only compile (hash-based)

Correctness guardrails:
- stale guard expands feature-targeted compile when other changed features are detected
- automatic full-compile fallback when prior build state is unavailable or invalid
- changed-only mode can no-op when no source changes are detected

## Runtime projections
Runtime uses emitted projections (build projections and compatibility mirrors), not hot-path folder scanning.

Notable shift:
- query registry can now load `query_index.php` projection
- legacy SQL scan remains as compatibility fallback

## Extension foundation
Compiler extensions register deterministically and are inspectable.

Current extension registry supports contributions for:
- pass stages
- projection emitters
- migration rules

Inspect registered extensions:
```bash
php vendor/bin/foundry inspect extensions --json
```

## Spec migration and codemods
Phase 0 includes migration foundations:
- `ManifestVersionResolver`
- `MigrationRule`
- `SpecMigrator`

Current core migration upgrades feature manifests to v2 conventions.

## Canonical development loop
1. Edit source-of-truth feature files.
2. Compile graph.
3. Inspect diagnostics and impact.
4. Verify graph and domain contracts.
5. Run tests.
6. Run the app.

Example:
```bash
php vendor/bin/foundry compile graph --json
php vendor/bin/foundry inspect graph --json
php vendor/bin/foundry inspect impact --file=app/features/publish_post/feature.yaml --json
php vendor/bin/foundry verify graph --json
php vendor/bin/foundry verify contracts --json
php vendor/bin/phpunit
```
