# Foundry Roadmap Phase 2

Phase 2 extends the graph-first compiler substrate with four new capabilities:

1. mail and notifications
2. API resource generation and OpenAPI export
3. graph-derived docs generation
4. deep test generation

These capabilities are compiler-native:

- source specs are versioned and discovered under `app/specs/*`
- specs compile into canonical graph nodes and edges
- runtime metadata is emitted as projections from graph state
- inspect/verify commands read graph-backed reality
- generation writes source-of-truth files first, then compile/projection emits runtime artifacts

## New Phase 2 Specs

`app/specs/notifications/*.notification.yaml`

```yaml
version: 1
notification: welcome_email
channel: mail
queue: default
template: welcome_email
input_schema: app/notifications/schemas/welcome_email.input.schema.json
dispatch_features: [dispatch_welcome_email]
```

`app/specs/api/*.api-resource.yaml`

```yaml
version: 1
resource: posts
style: api
features: [list, view, create, update, delete]
feature_names:
  list: api_list_posts
  view: api_view_post
  create: api_create_post
  update: api_update_post
  delete: api_delete_post
```

## Compiler and Graph Integration

Phase 2 adds:

- `notification` nodes
- `api_resource` nodes
- phase2 link pass (`phase2_specs`)
- projections:
  - `notification_index.php`
  - `api_resource_index.php`
- codemod:
  - `phase2-spec-v1-normalize`

## CLI Surface

Generation:

- `php vendor/bin/foundry generate notification <name> --json`
- `php vendor/bin/foundry generate api-resource <name> --spec=<file> --json`
- `php vendor/bin/foundry generate docs --format=markdown --json`
- `php vendor/bin/foundry generate docs --format=html --json`
- `php vendor/bin/foundry generate tests <target> --mode=deep --json`
- `php vendor/bin/foundry generate tests --all-missing --mode=deep --json`

Inspect and preview:

- `php vendor/bin/foundry inspect notification <name> --json`
- `php vendor/bin/foundry inspect api <name> --json`
- `php vendor/bin/foundry preview notification <name> --json`

Verify and export:

- `php vendor/bin/foundry verify notifications --json`
- `php vendor/bin/foundry verify api --json`
- `php vendor/bin/foundry export openapi --format=json --json`
- `php vendor/bin/foundry export openapi --format=yaml --json`

## Docs and OpenAPI Derivation

OpenAPI export and generated docs derive from compiled graph state:

- feature nodes
- route metadata
- schema payloads
- auth/permission metadata
- event/job/cache relationships

No separate parser or registry is used for API contracts, docs, or deep-test context selection.

## Deep Test Generation

`generate tests --mode=deep` uses graph-aware feature metadata to add scenario tests for:

- auth failure
- validation failure
- not-found routes
- DB side effects
- event emission
- job dispatch
- API output/error envelope behaviors
- listing/filter/sort behavior hints
- notification dispatch hints

## Workflow

1. edit specs or feature source files
2. compile graph
3. inspect graph/impact/diagnostics
4. run verifiers
5. run tests
6. export docs/OpenAPI when needed
